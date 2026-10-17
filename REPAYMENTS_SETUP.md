# Cash repayments for customer utang

Run `php artisan migrate` to add repayment metadata to the existing credit ledger. Existing customers, sales, charges, and balances are retained.

## Customer flow

Use **Record payment** in the customer list or ledger. Enter the cash received, or select **Pay full balance**. Notes are optional. The review step shows the customer, cash amount, and balance before/after payment. **Back to payment** has initial keyboard focus. Cancel/Escape before saving preserve the existing balance. Only **Confirm & record payment** writes to the API.

Successful payments appear in the ledger with a `PAY-...` reference, amount, date, notes, and the cashier's saved name. The customer list includes an all-time **Utang collected** figure. Settled customers no longer show a Record payment action.

## API

Prefix: `/api/v1/stores/{store}/customers/{customer}`. Both routes use authenticated active store-owner access and scoped customer bindings. POST uses existing Sanctum cookie/CSRF protections.

| Method | Suffix | Purpose |
| --- | --- | --- |
| POST | `/payments` | Record a partial or full cash repayment |
| GET | `/payments/by-key/{key}` | Recover a saved repayment by its UUID |

```json
{
  "request_key": "c7d25b22-6349-4dac-8a6b-c893e6deec19",
  "method": "cash",
  "amount_centavos": 10000,
  "expected_balance_centavos": "35000",
  "notes": "Partial payment received"
}
```

The reviewed balance is a decimal integer **string**, keeping large balances exact. Amount must be a positive integer, no greater than the locked outstanding balance or 1,000,000,000 centavos (PHP 10,000,000.00) per payment. Zero, negative, fractional-centavo, unsupported-method, and overpayment requests are rejected. Arbitrary balances, actors, customers, sales, and ledger entry types cannot be assigned through the payload.

The service locks store then customer, matching credit checkout's lock order. One transaction reduces the balance and appends an immutable `payment` entry with before/after balances and customer/cashier snapshots. A failed ledger write rolls back the balance. Existing debt can be collected from an archived customer through the authorized API.

Payments apply to the customer's total balance. Original sales and historical charge snapshots remain immutable; there is no per-sale payment allocation in this phase. Repayments create no new sale, sale payment, or stock movement. Sales history's cash figure covers cash sales; repayment collections are tracked separately to avoid counting the same sale twice.

## Confirmation, conflicts, and retries

A changed reviewed balance returns 409 with `code: balance_changed`. The client refreshes the balance, preserves the amount/notes, and requires a new review and confirmation. New accepted payments return 201. Retrying the same key and exact details returns the original entry with 200 and `meta.replayed: true`, even after the balance changes. A reused key with different details returns 409 with `code: repayment_key_conflict`.

Before POST, the client retains the exact request and minimal customer display data in per-user/store **sessionStorage**. Credentials and private customer notes are excluded; the entered payment note is retained for exact retries. If storage is unavailable, payment is not submitted. During an uncertain outcome, new payments on the customers page are locked. **Check saved payment** retrieves the original record; **Retry same payment** reuses the original key and payload. Closing the dialog or reloading the same tab exposes a pending-payment banner. A missing lookup retains recovery because an original request may still finish. Closing the browser tab or clearing its storage removes local recovery data; check the ledger before manually repeating an uncertain collection.

Saved repayment entries expose their historical balance after payment. The current customer balance may differ after later transactions. Ledger entries cannot be edited/deleted through these APIs. Refunds/reversals, down payments at POS, other payment methods, and per-sale allocations remain separate workflows.

## Verification

```powershell
php artisan test --compact
```

Tests use guarded SQLite `:memory:`. Coverage includes partial/full settlement, safe retries, stale reviewed balances, rollback, exact large balances, archived debt collection, snapshots, role/store/customer isolation, immutable history, and separate collection totals. Local MySQL checks exercise competing full repayments, same-key retries, and simultaneous repayment/credit checkout.

From `frontend`:

```powershell
node --test src/auth/auth.test.mjs src/catalog/catalog.test.mjs src/pages/pos/pos.test.mjs src/customers/repayments/repayments.test.mjs
npm run build
```
