# Cash POS and sales history

Cash and Utang are now supported in POS. See [UTANG_SETUP.md](UTANG_SETUP.md) for customer selection, inline registration, credit payloads, balances, and ledger behavior. The cash flow below remains supported.

Run `php artisan migrate` to add sales, sale items, cash payments, and the inventory movement sale reference. The migration retains existing users, products, inventory, and movements. The client remains on `http://localhost:3000`; the independent admin remains on port `3001`.

## Store APIs

All routes require an active authenticated store owner, use `/api/v1/stores/{store}`, and follow the existing Sanctum cookie and CSRF flow. Store and child-sale bindings are scoped. Missing and foreign resources return the same generic 404. Platform administrators receive no automatic store access.

| Method | Suffix | Action |
| --- | --- | --- |
| POST | `/sales` | Complete a cash sale |
| GET | `/sales` | Paginated completed sales and filtered totals |
| GET | `/sales/{sale}` | Saved sale, item snapshots, and cash payment |
| GET | `/sales/by-key/{key}` | Recover a completed checkout by its UUID |

There are no completed-sale edit/delete endpoints. Full-sale refunds and voids use a separate audited workflow that preserves the original sale; see [OPERATIONS_SETUP.md](OPERATIONS_SETUP.md).

## Checkout

The client opens **Review cash sale** before submitting. Its confirmation dialog shows item quantities and prices, subtotal, discount, total, cash received, and change. **Back to cart** has initial keyboard focus; cancel, Escape, and closing the dialog preserve the order without sending a checkout request. Only **Confirm & save sale** submits. While saving, cart edits and dialog dismissal are disabled. A price or stock rejection closes the dialog and refreshes the cart; the owner must review and confirm the updated order again.

```json
{
  "idempotency_key": "c7d25b22-6349-4dac-8a6b-c893e6deec19",
  "items": [
    { "product_id": 17, "quantity": 2, "expected_unit_price_centavos": 1525 }
  ],
  "discount_centavos": 105,
  "payment": { "method": "cash", "tendered_centavos": 5000 }
}
```

Money uses integer centavos. Quantities are whole units. A sale accepts 1–100 distinct products, and its subtotal cannot exceed 1,000,000,000 centavos (PHP 10,000,000.00). Discount cannot exceed the subtotal, and cash must cover the final total. Fully discounted sales accept zero cash. The API rejects browser-supplied totals, payment amounts, status, sale numbers, actors, and tenant identifiers.

Laravel calculates prices, line totals, discount, total, and change from locked store records. The optional expected price guards against price changes after the client adds an item; the client always sends it. Price changes, archived products, and insufficient stock return 409 before anything is saved. The client refreshes the basket for review and requires another explicit checkout.

One database transaction locks the store, products in ID order, and inventory; creates the sale, immutable product/store/cashier snapshots and cash payment; deducts stock and advances its version; and appends linked `sale` movements. A failed write rolls back all of these changes. Existing products remain sellable after a plan downgrade, including when active product usage exceeds its allowance.

## Safe retries and recovery

Use a fresh UUID for a new checkout. Retrying the same key and payload returns the original sale with HTTP 200 and `meta.replayed=true`; a new sale returns 201. A key reused with different checkout details returns 409 with `code: "idempotency_conflict"`. Replay is checked before current stock and prices, so later catalog changes cannot charge or deduct stock again. Keys are scoped to the store, and their fingerprint includes the authenticated cashier.

Before submitting, the client saves the exact checkout request and basket display data in **sessionStorage**, scoped by user and store. Credentials and tokens are never included. While an outcome is uncertain, the basket is locked. Reloading the same tab offers **Check saved sale** and **Retry same checkout**. A missing lookup keeps recovery available because an original request may still finish. Retries keep the original key and payload. Definitive business rejections unlock and refresh the cart.

Recovery depends on retaining that browser tab's session storage. If it is unavailable, the client does not submit checkout. Closing the tab or clearing its storage removes local recovery data; check sales history before manually repeating an uncertain purchase. This phase requires an API connection and does not support offline checkout.

## History and summaries

Sales lists accept `page`, `per_page` (1–100), `q` (sale number), `date_from`, and `date_to`. Dates are inclusive Manila business days, converted to UTC bounds in Laravel. `meta.summary` contains filtered transaction count and exact centavo strings for gross/net sales, discounts, and net cash collected. `meta.snapshot_id` can be supplied as `through_id` on later pages to keep an export stable while new sales arrive.

The client includes real sales search, date filters, pagination, saved detail dialogs, full filtered CSV export, text summary download, and an authenticated `/sales/{saleId}/print` page. Summaries use saved product names/prices and store/cashier snapshots, even after catalog changes. Inventory history links each sale deduction to its saved transaction. These are sales summaries; invoice and fiscal integrations are outside this phase.

## Scope and validation

Cash and utang checkout, customers, credit charges and cash repayments, products, categories, inventory, sales history, Dashboard charts, and reports are connected. See [REPAYMENTS_SETUP.md](REPAYMENTS_SETUP.md) for collections and settlement, and [OPERATIONS_SETUP.md](OPERATIONS_SETUP.md) for live Settings, camera scanning, and full-sale void/refund. Online payment processing, partial/item refunds, and paid billing remain future work.

```powershell
php artisan test --compact
```

Backend tests use guarded SQLite `:memory:` and cover totals, validation, tenant isolation, snapshots, rollback, safe retries, price/stock conflicts, Manila date boundaries, pagination, and stable exports. Frontend validation from `frontend`:

```powershell
node --test src/auth/auth.test.mjs src/catalog/catalog.test.mjs src/pages/pos/pos.test.mjs
npm run build
```

The frontend tests exercise exact cart arithmetic, stock limits, stale-line removal, recovery persistence/validation, storage scoping, late-response cleanup, and Manila dates. Local MySQL concurrency checks verify competing checkouts, simultaneous retries of one key, and reversed multi-product carts without negative stock or duplicate deductions.
