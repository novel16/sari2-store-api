# Live Settings, camera scanner, and full-sale void/refund

Client `http://localhost:3000`; API `http://localhost:8000`; admin remains independent on port `3001`.

## Install and migrate

From `frontend`, run `npm install`. The scanner uses `@zxing/browser@0.1.5` and compatible peer `@zxing/library@0.21.3`, avoiding the newer library's Node 24 requirement on this project's Node 22 environment. ZXing loads dynamically when the camera is opened. API usage follows the [official ZXing browser documentation](https://github.com/zxing-js/browser).

From `api`, run `php artisan migrate`. Additive migration `2026_10_16_180000_create_sale_reversals` adds an append-only reversal table plus nullable audit references in inventory movements and the credit ledger. It preserves all existing sales, items, payments, movements, and charges. It has already been applied to this local database; no reset or reseed is needed.

## Settings

The Settings page has real Store details, Profile, and Password sections. Preview-only fields/preferences were removed. Supported store fields are name (120), address (255, optional), and phone (30, optional). Supported owner fields are name and sign-in email.

- `PATCH /api/v1/stores/{store}` requires active owner/store membership; body: `name`, `address`, `phone`. It shares the store lock used by checkout. Future sales snapshot updated details; historical summaries keep their saved store/cashier information. It does not rename the billing account or change the store slug/status.
- `PATCH /api/v1/me/profile` requires `name` and `email`. Changing email requires `current_password`; emails are normalized and unique. Email changes clear verification/reset tokens and revoke other sessions/tokens; a name-only change does not require a password.
- `PATCH /api/v1/me/password` requires `current_password`, new `password` (8–72 bytes, no NUL), and `password_confirmation`. Current password is verified again under a user row lock. Other DB sessions, personal tokens, remembered login credentials, and reset links are invalidated. Existing AuthenticateSession middleware also rejects stale password hashes for other session drivers. This browser's session stays authenticated and its session ID is regenerated after password changes.

Identity routes are throttled. Client form submissions prevent double writes, show server validation, clear password inputs after success, and update the session cache without unmounting the active Settings section. No passwords/tokens are stored in local/session storage. Profile settings do not add an email-verification delivery workflow.

## Camera barcode scanner

POS → Scan barcode → Use camera scanner. The browser asks permission only after this action; the rear camera is preferred and multiple cameras can be selected when available. A detected barcode is looked up in the current store and added once to the cart, then the scanner closes/stops. Unknown/out-of-stock products retain the normal lookup error. Manual typing and keyboard-wedge scanners remain available, including after camera failure.

Camera access requires a secure context: HTTPS on actual devices (or browser-recognized localhost during desktop development). An actual phone needs the frontend/API's correctly configured HTTPS origins; a phone's localhost refers to the phone itself. There is no deployment/domain change in this implementation.

Camera frames are decoded locally; they are not uploaded. Unmount, stop, successful scan, or camera switch stops media tracks/decoder controls. Pending permission/import/decode operations also stop late-arriving streams after closure. Permission denied, missing hardware, busy camera, and unsupported context show readable manual fallbacks.

## Void and refund policy

The user approved **full-sale reversals only**, an optional all-items restock, and the pooled-debt rule below. Partial/item refunds are not implemented.

Sales history → sale details → Void / refund sale → choose kind, reason (3–255), and whether all items are physically returned to stock → Review reversal → confirm the shown cash correction → Confirm & save reversal. Back/Cancel sends no reversal. Only the final confirmation posts. Close/Escape and editing are locked during submission.

- Cash sale: refund the original discounted total, excluding change, not the tendered cash.
- Utang sale: reduce the customer's **current total outstanding debt** by `min(original sale total, current balance)`. Any remaining value is recorded as cash refunded. Payments are customer-level, so this is deliberately not per-sale payment allocation. Archived customer debt can still be reversed. Example: original sale PHP 29, current total balance PHP 19 → reduce debt PHP 19 and refund cash PHP 10. If other debt keeps the total balance above PHP 29, reduce debt PHP 29 and refund no cash.
- The review pins the exact customer balance. Another repayment/charge/reversal causes a conflict and requires another review. The API calculates amounts; it rejects injected money/stock/tenant fields.
- Restock adds the original quantities with audited `sale_return` movements and incremented inventory versions, including archived products. The current product unit must match the sale's saved unit; a legacy mismatch returns a 422 `restock` field error and rolls back the entire reversal. Choose no restock and reconcile the physical return separately in its correct unit. No-restock leaves quantity untouched and permits the financial reversal. Inventory remains capped at 1,000,000,000 units; failure rolls back the entire reversal.
- A sale can be reversed once, enforced by a unique sale constraint and transactional store/sale/customer/product/stock locks. Same request key/details safely replays. A different key for an already reversed sale or changed details for a used key returns 409.

Original sale rows remain immutable completed checkout records; API status is derived as `voided`/`refunded` from the linked reversal. Original items, tender/change, store/cashier snapshots, charges, and repayments remain intact. A credit reversal is a new decreasing ledger entry, with a separate reversal FK so the unique original charge/sale link remains valid. Reversal reference, actor snapshot, reason, cash/debt amounts, stock choice, balance snapshots, and time are preserved. There are no edit/delete reversal endpoints. This records cash adjustments; it does not issue electronic payments.

## Reversal API and recovery

All endpoints use existing active-user, owner/store membership, and scoped binding middleware. Platform admins have no automatic private-store access.

- `GET /api/v1/stores/{store}/sales/{sale}/reversal-preview`: current amounts, balance, and item quantities.
- `POST /api/v1/stores/{store}/sales/{sale}/reversals`: required UUID `request_key`, `kind` (`void`/`refund`), `reason`, boolean `restock`, nullable exact string `expected_balance_centavos`, and boolean `cash_confirmed` (must be true when cash refund is positive). New result 201; identical replay 200; response includes the saved sale and reversal audit.
- `GET /api/v1/stores/{store}/sale-reversals/by-key/{key}`: recover an uncertain committed result.

Before POST, the exact request is retained in per-user/store sessionStorage. Network/5xx outcomes retain it across dialog close, navigation away/back, and reload in the same browser tab. Sales exposes a pending banner and blocks starting another reversal until checking the saved result or retrying the identical request/key. Lookup 404 retains recovery and offers Retry. Definite validation/stale-state failures clear the attempt so the user can review again; key conflicts remain pending. Late successful responses cannot remove another pending request. Session storage unavailability prevents submitting a new reversal. Closing the tab/clearing browser storage removes local recovery, but the audit remains in sales history.

## Reporting and history

Reports/Dashboard retain original sales on their **checkout date**. Reversals offset revenue/cash on their **own recording date**, avoiding retroactive deletion of older period sales. Shared `StoreActivity` adds `reversal_count`, `reversed_sales_centavos`, `cash_refunds_centavos`, `net_after_reversals_centavos`, and `net_cash_collected_centavos` to summary/daily data. The last two may be negative when refunding older sales.

Dashboard's Today sales card includes these reversal offsets; cash panels show gross collections, refunds, and net cash. Reports shows a dedicated reversal/net totals section. Existing sales graphs, transaction counts, average sale, and product/unit rankings retain checkout figures and are labeled before returns/reversals. Daily figures and report CSV include reversal offsets and exact signed net amounts. Sales-list summary cards exclude currently reversed sales in that list filter; list rows, print/download summaries, stock history, customer ledger, and sales CSV visibly retain the reversal audit and original transaction details. Sales-list cards describe current unreversed records, while period reports follow event dates.

## Files and verification

Backend: Settings requests/controller/service; `ReverseSale`, reversal request/controller/resource/model; additive migration; shared reports calculations; existing sale/stock/customer resources and queries. Client: modular `src/settings`, `src/scanner`, `src/sales/reversals`, separate Settings/Sales workspaces, history/summary/report integrations. `App.tsx` stays small.

```sh
# api — guarded SQLite :memory:, never resets the local MySQL database
php artisan test

# frontend
node --test src/auth/auth.test.mjs src/catalog/catalog.test.mjs src/pages/pos/pos.test.mjs src/customers/repayments/repayments.test.mjs src/dashboard/dashboard.test.mjs src/reports/reports.test.mjs src/sales/reversals/reversals.test.mjs src/scanner/scanner.test.mjs
npm run build
```

Tests cover credential proof/validation/session revocation, historical settings snapshots, cash/credit reversals, pooled debt, duplicate keys, stale reviews, rollback, tenant/admin restrictions, historical event-date/negative totals, exact recovery, camera fallbacks/cleanup, and signed CSV values. Browser checks use isolated QA accounts that are removed afterward. Camera decoding can be verified with actual generated EAN-13 video frames through ZXing; a real phone-camera test under HTTPS remains an additional device-specific check.
