# POS drafts and customer operations

Client remains `http://localhost:3000`, API `http://localhost:8000`, admin `http://localhost:3001`. GCash and live Subscription/billing are deliberately deferred. No schema changes, migrations, resets, new packages, or credentials are required for this update.

## POS draft and discard confirmation

An editable order is saved to tab-local sessionStorage under a separate user/store draft key. Product quantities, discount, entered cash, Cash/Utang selection, and basic customer identity survive navigation and refresh in the same tab. Customer notes and credentials are not included. Closing the tab or clearing storage removes the draft; this is not offline selling or stock reservation.

A restored order requires **Refresh restored order** before review. The API reloads current product prices/stock and the chosen customer; an archived customer is deselected. Checkout still validates everything server-side. Stale/archived product lines can be reduced or removed. An existing unresolved checkout always takes precedence over the editable draft.

New order and Clear cart ask for confirmation when the order contains any draft details. **Keep order** and closing the dialog preserve it; only **Discard & start new order** clears it. Neither action creates a sale. Successful checkout clears the draft. Once checkout is submitted, the separate existing financial recovery record owns it and retries keep the original key. Explicit sign-out clears only that user's editable drafts; unresolved financial recovery stays separate. Storage failures display a warning and enable a browser leave warning.

## Customer maintenance and archived debt

Customers supports Active/Archived/All filters, editing name/phone/notes, and explicit archive/restore review. There is no delete endpoint. Original sale/customer/cashier snapshots and payment/opening snapshots remain unchanged when the current customer profile is edited.

- `GET /api/v1/stores/{store}/customers/{customer}`: current profile and opening eligibility.
- `PATCH /api/v1/stores/{store}/customers/{customer}`: required name, optional phone/notes. Balances, status, tenant IDs, and request keys cannot be changed here.
- `PATCH /api/v1/stores/{store}/customers/{customer}/status`: `status: active|archived` only.
- Customer list accepts `status=active|archived|all` (default active, including the POS picker). Summary figures cover the entire store, including archived debt, independently of list filters.

Archiving hides the customer from new utang sales, but keeps their debt, statements, refunds, and repayment flow available. Archived customers can still repay. Restore makes them selectable for new utang again. Store/customer row locks use the same order as checkout, payments, and reversals.

## Audited opening utang

Customers → **Opening utang** → amount and notebook reference/reason → Review → Confirm & record opening. Back/Cancel makes no financial write. The entry records debt carried over from before using the app; it creates no sale, payment, cash collection, or stock movement. Reports retain that separation, and outstanding debt includes the opening.

Policy: one positive opening, up to PHP 10,000,000.00, for an active customer with zero balance and **no financial history**. It cannot be added after a sale/payment/reversal or after settlement of earlier debt. Record it before starting normal transactions for that customer. The recorded entry is append-only; no edit/delete endpoint is added. This prevents a late opening from silently rewriting historical balances.

- `POST /customers/{customer}/opening-balance` under the store prefix: UUID `request_key`, integer `amount_centavos`, required `notes` (3–255 characters).
- `GET /customers/{customer}/opening-balance/by-key/{key}`: recover a saved result.
- New result 201, identical replay 200, different details on an existing key 409, no-longer-eligible customer 409. All operations lock the store then customer. The existing unique store/request key and transaction make retries safe. Same-key replay precedes current eligibility checks.
- Ledger uses `entry_type=opening_balance`, a positive amount, before/after balance, `OPEN-...` reference, actor/customer snapshots, reason, and recording time. Existing ledger fields support this without a migration.

Before submission the exact request is retained in per-user/store sessionStorage. Network/5xx outcomes preserve it across dialog closure, navigation, and reload. **Check pending opening** offers lookup and retry of the exact original request. A missing lookup does not discard recovery. Definite validation or eligibility failures clear the attempt; key conflicts retain it. The UI blocks a second opening/repayment while this opening is unresolved. Closing the tab/clearing storage removes local recovery; the saved entry remains in the ledger.

## Statements and repayment acknowledgments

Customer ledger → **View / print statement** opens an authenticated document with full financial history, opening debt, utang sales, cash repayments, reversals, and outstanding balance. CSV and text exports use the same loaded snapshot. Print can also use the browser's Save as PDF.

- `GET /customers/{customer}/statement`: ascending, paginated immutable entries (up to 100 per page), store/customer header, totals as exact strings, `generated_at`, `snapshot_id`.
- Subsequent pages send `through_id={snapshot_id}`. The snapshot is captured under the same store lock as balance writes; balance/totals remain pinned even when a new payment arrives. `through_id=0` represents an empty statement. A nonzero upper entry must belong to that customer.
- Client route: `/customers/{customerId}/statement`. All pages are loaded with cancellation support before printing/exporting; the first header and financial cutoff are retained.
- `GET /customers/{customer}/payments/{payment}` reads only that customer's cash repayment. Client route `/customers/{customerId}/payments/{paymentId}/print` loads a printable acknowledgment.
- Both the saved-payment dialog and previous ledger payments offer text download and print. Names/phone/actor, amounts, time, notes, and before/after balances come from the saved payment. Reprinting does not post another payment.

All new routes retain active-user, owner/store membership, and tenant scoping checks. Platform admins receive no private-store access through these endpoints.

## Verification and local limits

```sh
# api — guarded SQLite :memory:, no changes to local MySQL data
php artisan test

# frontend
npm run lint
npm run build
node --test src/auth/auth.test.mjs src/catalog/catalog.test.mjs src/pages/pos/pos.test.mjs src/pages/pos/draft.test.mjs src/customers/repayments/repayments.test.mjs src/customers/customerOperations.test.mjs src/dashboard/dashboard.test.mjs src/reports/reports.test.mjs src/sales/reversals/reversals.test.mjs src/scanner/scanner.test.mjs
```

Coverage includes archive/restore with retained debt, historical identities, opening idempotency/validation/rollback, tenant/admin boundaries, opening/report separation, statements pinned across concurrent payments, draft parsing/isolation, recovery validation, exact export values, and payment-slip snapshots. Help copy now explains saved records and real checkout. Mobile table overflow is contained so confirmation dialogs stay within the visual viewport; POS quantity controls use 44px tap targets on small screens.

Verified locally: 347 backend tests (2,779 assertions), 58 frontend tests, ESLint, and production build. Isolated MySQL/browser checks passed for SPA/reload draft restoration, explicit discard review, cash-checkout draft cleanup, one opening POST on double-click, recovery after losing a committed response, customer edit/archive/repayment/restore with preserved debt and snapshots, statement/payment print pages and downloads, and 390px archive/restore confirmation bounds. QA accounts and their fixtures are removed afterward.

The local setup currently uses HTTP and `MAIL_MAILER=log`. Password-reset notifications have automated backend coverage; the log transport delivers no external email. Actual SMTP delivery requires a configured transport and a designated recipient. No external email is sent and no mail secrets are changed by this work.

Before a physical-device pilot, verify phone-camera decoding on configured HTTPS frontend/API origins, Android portrait keyboard/tap behavior, and actual reset-link delivery. Desktop mobile emulation does not replace a physical camera/device test. Staff, branches, imports, pack conversions, expenses, partial refunds, offline selling, GCash, and paid subscriptions remain separate future work.
