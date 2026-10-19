# Plan catalog and entitlements

The admin app manages plans at `http://localhost:3001/plans`. The store app reads live prices, the effective current plan, and product usage at `http://localhost:3000/subscription`.

## Database setup

```powershell
php artisan migrate
php artisan db:seed --class=PlanSeeder
```

The additive `2026_10_16_200000_add_plan_catalog_and_versions` migration adds catalog fields, immutable `plan_versions`, and an optional subscription version reference. Existing store/financial records are retained. The migration and catalog seeder have already been run on this local database.

| Code | Monthly price | Active product allowance | Stores | Staff, owner excluded | Status |
| --- | ---: | ---: | ---: | ---: | --- |
| free | ₱0 | 100 per store | 1 | 0 | Published |
| basic | ₱49 | 500 per store | 1 | 0 | Published |
| plus | ₱149 | 1,000 per store | 1 | 2 | Coming soon |
| pro | ₱249 | 3,000 across the account | 3 | 5 total | Coming soon |

Money is stored as integer centavos. The seeder initializes missing plans and legacy version-zero entries. It normalizes the existing local Free allowance to the agreed 100 products. Rerunning it preserves plans already managed at version one or later, including administrator edits. The default DatabaseSeeder calls only PlanSeeder; it does not create a sample user or administrator.

## Admin management

All plan endpoints inherit the active platform administrator middleware:

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/api/v1/admin/plans` | Paginated catalog, including drafts/retired entries and subscription counts |
| POST | `/api/v1/admin/plans` | Create a plan and its first saved version |
| GET | `/api/v1/admin/plans/{plan}` | Plan settings and current version |
| PATCH | `/api/v1/admin/plans/{plan}` | Validate and save the next version |
| GET | `/api/v1/admin/plans/{plan}/versions` | Paginated immutable terms and change notes/actors |

Use `expected_version` on updates. Concurrent/stale submissions receive HTTP 409 and cannot overwrite another edit. Codes are immutable after creation. Every managed save requires a reason; the actor comes from the authenticated session. Retire a plan through its catalog status instead of deleting it.

The Free fallback must stay published and priced at zero. Publication is rejected when a plan advertises staff, multiple stores, or planned features that have not shipped. These capacities can be recorded on Coming soon/Draft plans. `catalog_status` controls visibility independently of existing subscriptions; the legacy `is_active` field no longer revokes paid access.

## Effective terms

Subscriptions belong to the store-owner account, never the platform administrator. The current subscription is the newest eligible record whose status is active and whose start/end dates include now. A future scheduled record does not hide the current paid period. When no eligible subscription exists, the current Free allowance is used.

Managed edits snapshot old paid terms before changing the catalog and attach all legacy subscriptions without a saved version in a single update. Existing paid subscribers continue using their saved prices and limits, including after catalog retirement. Free accounts follow the current Free allowance. Catalog history records version-zero legacy terms when first initialized.

Manual Basic activation/renewal assigns the matching saved version and appends reviewed periods after payment verification or a reasoned complimentary grant. Owners can also purchase Basic through [PayMongo QR Ph](PAYMONGO_SETUP.md); its server-priced order saves the purchased version, and only a verified webhook marks payment paid before automatic fulfillment. See [manual subscriptions](SUBSCRIPTIONS_SETUP.md) for retained administrator endpoints, audit and duplicate protection. Publishing a plan does not charge or upgrade anyone.

## Usage and quotas

`GET /api/v1/stores/{store}/subscription` requires the existing owner/store authorization. It returns effective terms, usage, store/staff counts, public plans, renewal status, a readiness-based `billing_available` flag and the unresolved `pending_order`. Configured installations offer QR Ph checkout; missing/disabled payment configuration keeps checkout unavailable. Drafts/retired offers are hidden; an existing subscriber can still see their own saved retired-plan terms.

Only active product entries count; stock quantity and archived products do not. Pro's account quota counts active entries across all stores in the account, including separate entries for the same item in different stores. Product creation/restoration takes an account lock before the existing store/product/inventory locks so simultaneous branches cannot consume the same final slot. Store-based allowances retain their previous behavior.

When over quota, viewing/editing/archiving existing products and normal cash/utang operations remain available. New product creation and restoration are restricted. Store/staff allowances describe the catalog; the existing owner-only, single-store workflow remains in place until separate staff/multi-store features are implemented. No paid feature is enabled merely by creating a plan.

## Organization and checks

Admin controllers/requests/resources/services stay under `Admin`. Shared terms and current-plan resolution are in `app/Services/Plans`; shared catalog resources and models are reused by both apps. Price/limit data comes from the API rather than frontend plan constants. The owner profile eager-loads subscription plans and saved versions, and plan lists use `withCount()` instead of per-row queries.

```powershell
php artisan test --compact --filter="PlanManagementTest|SubscriptionOverviewTest|QueryScalingTest"
php artisan test --compact
```

Coverage includes seed idempotence, administrator/owner isolation, validation, immutable versions, stale edits, paid term preservation, future periods, expiry/overage, pooled quotas, and constant query counts. Frontend tests cover exact peso conversion, credentialed versioned writes, safe plan links, and usage labels/bars. Both apps retain build/lint checks.
