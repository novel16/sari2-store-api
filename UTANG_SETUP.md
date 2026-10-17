# Customers and POS utang

Run `php artisan migrate` to add store customers, credit ledger entries, and credit fields on sales. Existing cash sales retain `payment_method: cash` and their original retry fingerprints. Existing records are retained.

## POS behavior

Cash is the default payment method. Choosing **Utang** replaces cash/change fields with a searchable customer picker and an adjacent **+ Add customer** button. Search accepts name or phone and supports paginated results and keyboard selection. Typing a new search clears the previous selection so checkout cannot silently use the wrong customer.

The customer modal requires a name; phone and notes are optional. Saving automatically selects the new customer in the existing order. Canceling preserves the cart and previous selection. New customers start with zero balance. Names and phone numbers may be shared by different people; IDs distinguish records, and the picker shows phone or customer ID.

Full utang requires an active customer and a positive sale total. No cash is collected during this checkout. The review dialog identifies the customer, amount to add, and estimated balance after the sale. Cancel/Escape preserve the order; only confirmation submits. Stock/price conflicts require another review.

## APIs

All routes require the existing authenticated active store owner and Sanctum session/CSRF protections. Prefix: `/api/v1/stores/{store}`. Customer/ledger resources are scoped to the store; missing and foreign records both return 404.

| Method | Suffix | Action |
| --- | --- | --- |
| GET / POST | `/customers` | Search/list customers / create a zero-balance customer |
| GET | `/customers/{customer}/ledger` | Paginated immutable charges and current balance |
| POST | `/customers/{customer}/payments` | Record partial/full cash repayment |
| GET | `/customers/{customer}/payments/by-key/{key}` | Recover a saved cash repayment |
| POST | `/sales` | Existing checkout, now supports `cash` or `credit` |

Customer creation accepts `request_key` (UUID), `name`, optional `phone`, and optional `notes`. Retries with the same key and normalized details return the original customer. Reusing the key with different details returns 409. Lists accept `q`, `page`, `per_page` (1–100), and `balance=all|outstanding|settled`. `meta.summary` contains store-wide customer count, count with balance, and outstanding centavos.

Credit checkout uses the existing sale payload, replacing its payment object:

```json
{ "method": "credit", "customer_id": 25 }
```

Cash tendered is prohibited on credit checkout; customer ID is prohibited on cash checkout. Amounts, snapshots, stock, and balances are calculated in Laravel. Credit sales create no cash-payment row and report `amount_paid_centavos: 0`, `credit_amount_centavos: total`, `payment: null`. Their total contributes to sales, while cash collected remains unchanged.

One transaction locks store, customer, products, and inventory; saves immutable customer/product snapshots; deducts stock; increases the customer's cached balance; and appends one charge linked to the sale. Failed writes roll everything back. Replaying a checkout never adds another charge or stock deduction. Balances are exposed as decimal centavo strings so large totals stay exact in JavaScript. Saved summaries identify Utang and the customer; ledger balance after sale is a historical snapshot.

The client retains customer identity, phone, and balance display data alongside an uncertain checkout in per-user/store **sessionStorage**; notes and credentials are excluded. Reload recovery retries the exact original customer and request key. New orders reset to Cash with no selected customer.

## Scope and verification

Customer list and ledger pages display saved charges and cash repayments. See [REPAYMENTS_SETUP.md](REPAYMENTS_SETUP.md) for partial/full settlement, confirmation, safe retries, and collection totals. Down payments, per-sale repayment allocations, credit limits, due dates, customer edits/archives, and charge reversals require a separate workflow; there are no ledger edit/delete endpoints.

Backend tests cover customer validation, tenant isolation, safe create retries, cash compatibility, authoritative charges, rollback, exact large balances, and duplicate checkout. Frontend tests cover mode-specific validation, recovery snapshots, and utang summaries. Local MySQL checks exercise simultaneous charges for one customer and concurrent retries of one checkout key.

```powershell
php artisan test --compact
```

From `frontend`:

```powershell
node --test src/auth/auth.test.mjs src/catalog/catalog.test.mjs src/pages/pos/pos.test.mjs src/customers/repayments/repayments.test.mjs
npm run build
```
