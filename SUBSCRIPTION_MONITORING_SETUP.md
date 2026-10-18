# Administrator subscription monitoring

Open Admin → **Subscriptions** at `http://localhost:3001/subscriptions`. This read-only account directory adds summary cards, debounced account/owner search, subscription and account-status filters, 7/30-day expiry windows, pagination, and a shortcut to each account's existing **Manage subscription** screen.

Activation/renewal still uses the established reviewed manual flow. Monitoring does not send reminders, collect payments, change periods, or suspend accounts. There is no new subscription or audit table.

## Setup and organization

```powershell
php artisan migrate
```

`2026_10_18_160000_add_subscription_monitoring_indexes` adds two subscription indexes for account/start lookups and remaining active-period scans. It does not rewrite subscriptions, plans, payments, or financial history. It has been applied to the local database.

The controller, request, resource, and service live under their respective `Admin` namespaces. The shared `Subscription::coveringAt()` scope preserves the existing `Account::currentSubscription` validity rule. The SPA's `src/subscriptionMonitoring` contains its types, URL filters, query composition, summary cards, and table; the route page stays small.

## State and coverage rules

The request captures one UTC instant. A current subscription must be active, start at or before that instant, and have no end or end strictly after it. If several qualify, the highest subscription ID wins, matching the existing effective-plan resolver. With no current subscription, Free applies. A current Free period takes precedence over an older overlapping paid period.

| Filter / state | Meaning |
| --- | --- |
| Active Basic | Effective current plan code is `basic`, including Basic expiring soon |
| Expiring soon | Effective current non-Free plan has finite continuous paid coverage ending within the selected next 7 or 30 days |
| Expired paid | Currently on Free, with at least one valid non-Free period that ended at or before now |
| Free | Currently on Free without an ended non-Free period; may have a future paid start |
| Other paid | Effective non-Free plan has a code other than `basic`, preserving visibility of legacy periods |

Here, paid coverage means a non-Free plan, including complimentary grants and legacy non-Free plans with a zero catalog price. These counts do not represent money collected. Active Basic and Other paid are distinct states; Expiring soon is an overlapping flag, so do not add that card again when totaling accounts. Expired paid is separate from the Free card even though its effective plan is Free.

Coverage merges active, valid non-Free intervals that overlap or touch, starting with the interval covering now. Contiguous prepaid renewals extend the coverage end. A gap, even one second, stops that coverage; a later paid restart remains visible as scheduled. Nested overlaps use a running maximum end, so a shorter nested period cannot create a false gap. Cancelled, Free, and invalid future intervals do not extend paid coverage. Open-ended coverage returns a null end and is never expiring.

For example, a current period ending in 2 days plus a touching renewal ending in 35 days does not appear in the 7/30-day expiring filter. If the renewal instead starts one day after the current end, the current coverage still ends in 2 days. The list displays both the current period end and continuous paid coverage end so those dates are clear.

The expiry upper bound is inclusive: request time plus the selected number of days in `Asia/Manila`. The lower bound is exclusive because a period ending exactly now has already expired. Dates display in Philippine time. Account and owner suspension are separate metadata and do not alter saved coverage. Scheduled periods are active valid future non-Free periods, with the next chosen by start time then ID. The last ended paid timestamp preserves the existing history rule regardless of its stored status.

Current and next non-Free names use saved version terms through `PlanTerms`, so editing the catalog does not rename the original offer. Free names follow the current Free catalog. Provider/payment references, grant reasons, full terms, credentials, and private POS records are excluded from this endpoint.

## API

`GET /api/v1/admin/subscriptions` requires an authenticated active platform administrator and rechecks current persisted administrator access. Platform admins remain independent of store memberships. Monitoring exposes no mutation routes.

| Parameter | Accepted values |
| --- | --- |
| `q` | Trimmed account name, owner name, or owner email; at most 120 characters |
| `status` | `all` (default), `active_basic`, `expiring`, `expired_paid`, `free`, `other_paid` |
| `expiry_days` | `7` (default) or `30` |
| `account_status` | `all` (default), `active`, `suspended` |
| `page` | 1–1,000,000; default 1 |
| `per_page` | 1–100; default 20 |

Search uses SQL LIKE, retaining `%` and `_` wildcard semantics. Results sort by account ID descending. Offset pagination is live: changes between requests can shift page membership; refresh obtains current state.

The response has `data`, `summary`, and `meta`. Each row explicitly contains account/owner metadata, effective state and plan, current period, continuous paid end, next scheduled period/count, and last paid end. A null `coverage_ends_at` means Free or open-ended paid coverage; inspect `is_paid` to distinguish these. Summary contains `total`, `active_basic`, `expiring`, `expired_paid`, `free`, and `other_paid`. It follows search, account-status and expiry-window choices, while ignoring the subscription-state filter and page. Metadata includes standard page counts, effective filters, `time_zone: Asia/Manila`, and `generated_at`.

Filters and page are saved in the SPA URL; search is debounced and changing filters resets the page. Loading, empty, validation/server errors, retry, and auth-expiry handling use the existing query infrastructure. The feature performs no browser-storage writes.

## Query behavior and verification

SQL derives current periods and continuous paid coverage before filtering, counts, or pagination. Separate window-query layers compute gaps and islands from narrow remaining-period projections. Only selected current/next periods and their plan/version relations are hydrated in fixed batches. The resource does not query inside its per-row serialization. A read transaction keeps summary, pagination and hydration consistent on supported database isolation settings, without taking write locks on accounts or stores.

```powershell
php vendor/bin/pest --filter=SubscriptionMonitoringTest --compact
php vendor/bin/pint --test
```

Feature coverage includes access changes, immutable names, effective/expired/future periods, open ends, overlaps/gaps, exact expiry boundaries, summaries, filters, stable ordering, privacy, unchanged records, and constant query growth. Admin `npm test`, lint and build cover mounted controls, cards, dates, pagination, safe internal links, and recovery views.

The opt-in real-database suite adds `MySqlSubscriptionMonitoringTest` for the window SQL, nested/touching/gapped renewals, snapshot reads, filters and query bounds. It also checks EXPLAIN index eligibility on synthetic fixtures. See [guarded database setup](docs/MYSQL_CONCURRENCY.md). Passing a query-count or small-fixture EXPLAIN check does not establish production latency; use [representative performance checks](docs/OPERATIONS.md) before launch.
