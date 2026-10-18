# Products, categories, and inventory

This phase connects the client on `http://localhost:3000` to store-scoped Laravel APIs. Run `php artisan migrate` to add the catalog/inventory tables. A second migration preserves subscription start dates when plans change: it replaces the legacy MySQL auto-updating timestamp behavior without changing existing date values. Existing accounts and stores are retained.

## Store APIs

Every endpoint below uses `/api/v1/stores/{store}` and requires an authenticated active owner of that store. Child product/category bindings are scoped to the authorized store. Foreign and missing resources both return a generic 404. Admin accounts receive no automatic store access. Writes use the existing Sanctum session and CSRF flow; credentialed CORS allows PATCH and DELETE from the configured frontend origins.

| Method | Suffix | Action |
| --- | --- | --- |
| GET / POST | `/categories` | Paginated list / create |
| PATCH / DELETE | `/categories/{category}` | Edit or restore / archive |
| GET / POST | `/products` | Paginated list / create with opening stock |
| GET / PATCH / DELETE | `/products/{product}` | View / edit or restore / archive |
| GET | `/products/lookup?barcode=001234` | Exact active product lookup |
| GET | `/inventory` | Paginated stock and store-wide active-stock summary |
| GET | `/inventory/movements` | Paginated append-only stock history |
| POST | `/inventory/adjustments` | Record stock in, stock out, or a counted correction |

Lists accept `page`, `per_page` (1–100), `q`, and `status=active|archived|all`. Products/inventory also accept `category_id` and `stock=all|low|out`. Movements accept `product_id`. Responses use `data`, Laravel pagination `links`/`meta`, and optional `meta.summary`/`meta.quota`.

## Product data and limits

Create fields: `name`, optional `description`, optional `category_id`, optional `sku`/`barcode`, `unit`, `price_centavos`, `cost_centavos`, `low_stock_threshold`, `initial_stock`, optional `emoji`/`color`, and optional `status`. Stock and tenant identifiers cannot be overwritten through product edits. Use the stock-adjustment endpoint for changes after opening stock.

A product's unit cannot change once it has stock, an inventory movement, or a saved sale item. Every newly created product has an opening movement, including zero stock, so choose its unit at creation; use a separate product for a different selling unit. Attempts return a 422 `unit` field error without saving other edits. Only unused legacy products with no stock or history can have their unit corrected.

Money is integer centavos. Price/cost and whole-unit quantities are bounded at 1,000,000,000. Inventory valuation is returned as a decimal integer **string**, keeping large centavo totals exact in JavaScript. The UI converts peso entry to centavos without floating-point rounding.

Codes use letters, digits, dot, underscore, and hyphen; they are normalized to uppercase. Barcodes remain strings, preserving leading zeros. SKU/barcode uniqueness is **per store**, including archived products; multiple absent codes are allowed. Category names are unique per store, ignoring case and surrounding whitespace, including archived names.

The active-product allowance is checked **per store**, using the current active plan. Missing, inactive, expired, future-dated, or disabled subscriptions fall back to Free. A null allowance on a valid plan means no configured product cap. Store-row locks serialize creation/restoration checks so concurrent requests cannot take the same final slot. Existing active products remain editable when usage exceeds a downgraded limit.

DELETE archives records; PATCH with `status: "active"` restores them. Product restoration rechecks quota and requires an active category or no category. A category with active products must have those products reassigned or archived first. Archiving retains inventory, movements, and reserved codes. Active-stock summaries omit archived products; movement history includes them.

## Stock integrity

Every product receives an opening movement, including zero opening stock. Stock changes and their movement records commit in one transaction, locking account, store, product, then inventory. Current account/store availability is rechecked after acquiring those workspace locks. Each movement records before/after quantities, signed delta, version, reason, actor, and timestamp. There are no movement edit/delete endpoints.

Adjustment example:

```json
{
  "product_id": 17,
  "mode": "add",
  "quantity": 5,
  "reason": "New delivery",
  "request_key": "0ac96cc9-978c-4604-8fc4-857e6df40bf6"
}
```

Modes are `add`, `remove`, and `set`. Negative stock, overflow, and changes of zero are rejected. `set` also requires `expected_version` from the product's current `inventory.version`; stale counts return 409. Add/remove operations calculate from the locked server quantity.

Use a fresh UUID `request_key` for each new adjustment. Retry **the same payload and key** after an uncertain network result. A successful replay returns 200 with `meta.replayed=true` and does not change stock again. Reusing the key with different details returns 409 `adjustment_key_conflict`; a stale count returns `stock_changed`, and an archived product returns `product_archived`.

The client saves the exact adjustment in per-user/store session storage **before** sending it. Uncertain outcomes freeze its details and block new adjustments until the same request is confirmed. Recovery survives modal close, navigation and reload in the same tab. Late responses after leaving the page retain recovery rather than silently acknowledging it. A known rejection from the adjustment POST permits a new review; CSRF errors, unknown conflicts, 404s, network failures and 5xx responses retain the original key. Unavailable/corrupt storage blocks writes. Closing the browser tab or clearing its storage removes local recovery, so use movement history to verify any unresolved adjustment before recording another.

## Client behavior

Products, categories, inventory, global product search, and stock reminders now use saved store records. The pages include loading/error/empty states, field validation, search, filters, pagination, archive/restore confirmations, and movement history. CSV export follows every page of the current inventory filter and escapes spreadsheet formulas.

Cash and utang POS checkout deduct live inventory and append linked sale movements. See [POS_SETUP.md](POS_SETUP.md) and [UTANG_SETUP.md](UTANG_SETUP.md) for safe checkout retries, customer charges, snapshots, and sales history. Dashboard sales, reports, paid billing, and Settings updates remain previews.

## Validation

```powershell
php artisan test --compact
```

Tests use the guarded SQLite `:memory:` database. Coverage includes two-store ID isolation, scoped uniqueness, relationship injection, opening-stock audit, rollback, quota restoration/downgrades, negative stock, stale counts, idempotent retries, pagination, filters, and exact inventory valuation.

Local MySQL checks additionally exercise simultaneous creation at the final quota slot, competing stock removals, and concurrent retries with the same adjustment key. Browser checks cover two owners, persistent CRUD/stock changes, validation, archives, history, pagination, CSV export, and mobile layouts.
