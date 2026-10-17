# Avoiding N+1 queries

Relationship loading belongs in the controller/query/service that prepares a response. Load all relationships needed by a collection before calling `get()` or `paginate()`:

```php
$stores = Store::query()
    ->with('account.owner')
    ->latest()
    ->paginate(20);
```

Use `withCount()` and `withExists()` when the response needs only a count or existence flag. Do not fetch entire collections to calculate these values, and do not call `count()`/`exists()` inside a resource for each row. Nested relations need nested eager loading, such as `reversal.sale` on customer ledger entries.

`CustomerResource` consumes the `ledger_entries_exists` projection to calculate opening-balance eligibility without querying. Customer list queries use `withExists('ledgerEntries')`; single-record response paths use `loadExists('ledgerEntries')`. Keep that projection whenever adding a new customer response. The resource omits the eligibility field if the projection is missing rather than querying or guessing eligibility.

For checkout and refund restocking, products and inventories are read and locked in batches. Preserve the transaction and lock order: **store → customer when needed → products ordered by ID → inventories ordered by product ID**. Eager-loaded inventory queries also use `lockForUpdate()`. Ordinary eager loading alone would not preserve the inventory locks. Every lookup stays scoped to the authorized store. Stock updates and immutable line/movement inserts remain inside the transaction.

`AppServiceProvider` enables Laravel's `Model::preventLazyLoading()` outside production so accidental relationship reads on collections fail during development and tests. This guard detects relationship lazy loading; it does not detect explicit `->exists()`, `->first()`, or other query calls inside loops. Query-count tests cover that separate case.

Product catalog changes now take the account lock before the store lock so pooled plan quotas are serialized across branches. Checkout/refund operations keep their existing store-first order and never acquire a subsequent account lock. Plan lists use a subscription-count projection; owner profiles eager-load saved plan versions with the effective subscription.

Manual subscription confirmation takes account → plan locks, then inserts the period and audit record atomically. Keep plan edits and activation on the same plan-row lock. Activation history uses immutable snapshots with no per-record relationship queries; scheduled-period status uses a bounded eager load plus aggregates. Subscription scaling tests compare one versus several scheduled periods because an empty relation legitimately skips its eager-load batches.

Regression tests compare database reads for small and larger requests, including JSON serialization. They cover admin directories/dashboard, catalog, inventory movements, customers, ledger/statement nested relationships, sales history, checkout, refunds, and owner workspace profiles. Mixed ledger pages include charges, legacy payments, and reversals at both sizes so constant extra relation batches are not mistaken for N+1 behavior. Write counts are excluded: each sold/returned item still needs its stock update and history records.

```powershell
php artisan test --compact --filter=QueryScalingTest
php artisan test --compact
```

These feature tests use the existing guarded SQLite `:memory:` setup. Add a query-scaling check when adding a list or a loop that consumes related records. A new fixed batch can legitimately change the total number of queries; increasing it per returned row or cart item is the regression these tests reject.
