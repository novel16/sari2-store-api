# Platform admin foundation

The admin SPA runs at `http://localhost:3001`; the store-owner SPA stays at `http://localhost:3000`. Both use the existing Laravel API at `http://localhost:8000`. The initial directory needs no extra migration; the plan-management extension has its own additive migration and seeder documented in [PLANS_SETUP.md](PLANS_SETUP.md).

## Architecture

Admin-specific code lives under explicit `Admin` namespaces:

```text
app/Http/Controllers/Admin/     # Dashboard and account/store directory endpoints
app/Http/Requests/Admin/        # Validated directory query inputs
app/Http/Resources/Admin/       # Explicit metadata response allowlists
app/Http/Middleware/Admin/      # Platform administrator authorization
app/Services/Admin/            # Platform counts and registration aggregation
app/Console/Commands/Admin/    # Secure platform administrator provisioning
routes/admin.php               # Admin read routes; middleware inherited from api.php
tests/Feature/Admin/           # Authorization, query, privacy, and dashboard tests
```

Shared models (`User`, `Account`, `Store`), Sanctum authentication, CSRF/session handling, login eligibility, and active-user checks stay shared. Directory controllers perform bounded reads; the overview service handles aggregation; resources serialize the response. Do not duplicate tenant models or authentication to create admin equivalents.

## Administrator account

```powershell
php artisan app:create-platform-admin
```

Run interactively, enter the administrator's name/email, provide and confirm the hidden password, then confirm creation. The command retains its existing name after moving into `Commands/Admin`. No seed password or public registration route is provided. Store registration cannot grant administrator privileges.

Only `platform_role = platform_admin` **and** `status = active` may access this area. No store membership is needed for a platform administrator. An administrator's platform role does not grant access to private store APIs.

## API contract

Authentication remains on the shared, already rate-limited session endpoints:

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/sanctum/csrf-cookie` | Issue CSRF cookie |
| POST | `/admin/login` | Authenticate an eligible administrator |
| POST | `/logout` | End the current browser session |

All following routes inherit `auth:sanctum`, `active.user`, and `platform.admin`:

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/api/v1/admin/me` | Current administrator identity |
| GET | `/api/v1/admin/dashboard` | Live counts, 30 daily registration buckets, latest five accounts/stores |
| GET | `/api/v1/admin/accounts` | Searchable/paginated account metadata |
| GET | `/api/v1/admin/accounts/{account}` | Account owner, status, registration dates, member/store counts |
| GET | `/api/v1/admin/stores` | Searchable/paginated store metadata |
| GET | `/api/v1/admin/stores/{store}` | Store profile, parent account, owner, and saved statuses |

Directory parameters: `q` (trimmed string, max 120), `status` (`all`, `active`, `suspended`), `page` (1–1,000,000), and `per_page` (1–100, default 20). The store directory also applies optional positive `account_id`. Results sort by creation date descending and ID descending for ties. Search matches account/store names and owner name/email. Search uses SQL LIKE matching; `%` and `_` retain their database wildcard meanings.

Lists return Laravel resource pagination (`data`, `links`, `meta`). Details and the dashboard return `{ data: ... }`. Registration dates use `Asia/Manila` calendar days with bound UTC boundaries; missing days contain actual zero counts. Summary statuses describe individual records, so an active store under a suspended parent account still has its own saved `active` status. User totals include administrators.

Directory resources expose platform metadata only. They do not serialize passwords, tokens, private store sales, products, customers, balances, or inventory. Account/store status mutations remain separate future work, with reason, review, and audit records. GCash and automatic billing are deferred.

The plan catalog adds administrator-only create/edit/read/version routes under `/api/v1/admin/plans`. See [plan management](PLANS_SETUP.md). Account-scoped manual Basic activation, renewal, and settlement history now have dedicated endpoints and screens; see [manual subscriptions](SUBSCRIPTIONS_SETUP.md).

## Verification

```powershell
php artisan test --compact --filter=PlatformReadTest
php artisan test --compact
php vendor/bin/pint --test app/Http/Controllers/Admin app/Http/Requests/Admin app/Http/Resources/Admin app/Http/Middleware/Admin app/Services/Admin app/Console/Commands/Admin tests/Feature/Admin routes/admin.php
```

Feature tests refuse to refresh any database except isolated SQLite `:memory:`. Coverage includes guests/owners/suspended administrators, real status totals, Philippine day boundaries, filters/pagination, response allowlists, private store isolation, missing records, invalid input, and absence of directory mutation endpoints. The provisioning tests continue checking interactive-only creation and password handling.

Admin directory and dashboard query-scaling tests verify that owner/account relationships stay batched as results grow. Follow [query practices](QUERY_PRACTICES.md) when adding new lists or related response fields.
