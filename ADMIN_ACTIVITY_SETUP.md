# Centralized administrator activity

The admin SPA exposes `/activity` at `http://localhost:3001/activity`. It combines existing platform audit records into a read-only timeline with search, module/action/administrator/date filters, cursor navigation, and links to the corresponding detail screens. It does not create another audit table or copy existing history.

## Sources and attribution

| Existing table | Module | Activity actions |
| --- | --- | --- |
| `admin_administrator_events` | Administrators | Created, suspended, reactivated |
| `admin_status_changes` | Accounts, stores | Suspended, reactivated |
| `plan_versions` | Plans | Plan version recorded |
| `subscription_activations` | Subscriptions | Activated, renewed |

This is the history already recorded by these features. Login attempts, profile/password edits, and private POS operations are outside this feed. A plan version can be a seeded or legacy baseline, so its generic label does not imply a historical creation/edit event that was never recorded.

Actor and target names come from saved snapshots. New plan changes also save `actor_name_snapshot`. Older plan versions retain their original attribution: an administrator ID without a recorded name displays `Administrator #ID (name not recorded)`. A missing actor ID displays System; console-provisioned administrators retain the recorded Console label. Current user names are never substituted for missing historical names. The administrator picker uses today's directory names solely to select an actor ID; it also includes suspended administrators and System / Console.

## Migration

```powershell
php artisan migrate
```

`2026_10_18_140000_add_activity_query_indexes_and_plan_actor_snapshot` adds the nullable plan actor snapshot and `(created_at, id)` / `(actor_user_id, created_at, id)` indexes to each source table. It does not rewrite existing audit rows or backfill names from current users. Existing deployments must apply this additive migration before using the endpoint.

## API

`GET /api/v1/admin/activity` requires an active platform administrator. The controller also verifies the current persisted administrator identity, so a previously loaded active user cannot retain access after suspension. There are no Activity mutation endpoints.

| Query parameter | Contract |
| --- | --- |
| `q` | Trimmed string, at most 120 characters; saved target name, actor name, or reason |
| `module` | `all`, `administrators`, `accounts`, `stores`, `plans`, `subscriptions` |
| `action` | `all`, `created`, `suspended`, `reactivated`, `plan_version_recorded`, `activated`, `renewed` |
| `actor_id` | Omit for all; `0` for System / Console; positive administrator ID otherwise |
| `date_from`, `date_to` | Supply both as `YYYY-MM-DD`; inclusive Philippine dates, at most 366 days |
| `per_page` | 1–100, default 20 |
| `cursor` | Opaque next/previous cursor returned by the server, at most 2048 characters |

Without dates, the range is the last 30 Philippine calendar days including today. Boundaries use `Asia/Manila` and are converted to UTC for SQL. Incompatible module/action combinations return an empty result. SQL LIKE search preserves `%` and `_` wildcard meanings. Invalid filters/cursors return 422.

The response contains `data` and `meta`. Each entry has a source-qualified `id`, source/source ID, module/action, occurrence time, recorded actor and target, reason, optional status transition, and limited plan/subscription details. Target paths are derived internal SPA links. Metadata contains the effective filters, page size, next/previous cursors, and `time_zone: Asia/Manila`; it does not run a total-count query.

Entries sort descending by creation time, source rank, then source ID. Source ranks are fixed in `ActivityLog::SOURCES`. The cursor retains this unique ordering across equal timestamps and avoids offset shifts caused by newer inserts. This is a live feed, not a frozen export: older late-committed records can appear during navigation. Refresh clears the cursor while retaining filters; changing a filter also restarts navigation. The UI keeps filters/cursors in the URL.

## Query and privacy boundaries

The service applies date, actor, module, action, and search predicates to each SQL source before combining small headers with `UNION ALL`. Native cursor pagination selects one bounded page, then the service loads its records in at most four batched queries. It does not load whole histories or resolve relationships per row. Query-count coverage checks this bound as the returned page grows.

`ActivityEntryResource` explicitly selects public audit metadata. It omits passwords, credential proofs, tokens, review/request keys and hashes, payment references, full proposal/result/terms snapshots, and private customer/sales/stock information. The UI also validates target paths before rendering links. Existing feature-specific history screens remain available for their own details.

Code is under `Http/{Controllers,Requests,Resources}/Admin` and `Services/Admin/Activity`. The SPA's page composes the `src/activity` filters, actor picker, timeline, types, and query state. No Activity business logic is placed in `App.tsx`.

## Verification

```powershell
php vendor/bin/pest --filter=ActivityLogTest --compact
php vendor/bin/pint --test
```

The isolated SQLite suite covers authorization/current access, snapshots, default/inclusive dates, filters, cursor validation/ties/round trips/newer inserts, source privacy, query growth, and read-only behavior. The admin's `npm test`, lint, and build cover mounted filters/search/pagination, loading/error/retry/empty states, actor selection, safe links, and legacy attribution.

The guarded opt-in database suite also runs `MySqlActivityLogTest` against the configured disposable schema to verify the SQL union, JSON search, cursor navigation, bounded reads, and unchanged source records. See [database test setup](docs/MYSQL_CONCURRENCY.md). This SQL compatibility case does not simulate concurrent writes; the separate race cases test transactional competition.
