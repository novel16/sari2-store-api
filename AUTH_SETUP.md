# Authentication setup

The Laravel API provides first-party Sanctum cookie/session authentication for the client at `http://localhost:3000`. The independent admin app uses port `3001`. Use the same `localhost` hostname for browser/API calls.

## Local configuration

Keep the existing `.env`. For a fresh checkout, copy `.env.example` to `.env`, fill in your own MySQL connection, and generate an application key only if `APP_KEY` is empty. Never commit `.env`, keys, reset links, or credentials.

```powershell
composer install
php artisan migrate
php artisan serve --host=localhost --port=8000
```

The new migrations add user access fields and account/membership/store/plan/subscription tables. They preserve existing rows. Do not run `migrate:fresh` against the application database.

Local settings:

```dotenv
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
ADMIN_URL=http://localhost:3001
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:3001
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:3001
SESSION_DRIVER=database
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
MAIL_MAILER=log
```

Production requires HTTPS, exact trusted frontend origins, secure cookies, a shared site/domain arrangement for the SPAs/API, and `APP_DEBUG=false`. Use actual deployment values rather than localhost defaults.

## Endpoints

Send `Accept: application/json`. Auth writes require a session and CSRF header from the `XSRF-TOKEN` cookie.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/sanctum/csrf-cookie` | Establish CSRF/session cookies |
| POST | `/register` | Create owner, account, store, and Free subscription; HTTP 201 |
| POST | `/login` | Owner session login; HTTP 200 |
| POST | `/admin/login` | Platform-admin session login; HTTP 200 |
| POST | `/logout` | Invalidate session and rotate CSRF; HTTP 200 |
| GET | `/api/v1/me` | Current identity and authorized account/store memberships |
| GET | `/api/v1/stores/{store}` | Authorized owner's store profile |
| GET | `/api/v1/admin/me` | Platform-admin identity only |
| POST | `/forgot-password` | Request a reset notification with a generic response |
| POST | `/reset-password` | Consume reset token and revoke old sessions/tokens |
| GET | `/api/v1/health` | Public JSON health check |

Registration fields are `name`, `store_name`, `email`, `password`, and `password_confirmation`. Owner roles and tenancy are assigned server-side; injected role/account/store fields are rejected. Passwords require at least 8 characters and at most 72 UTF-8 bytes for the configured bcrypt hasher. Null bytes are rejected to prevent password truncation.

Only active owner memberships are enabled in this phase. Suspended users, accounts, memberships, or stores cannot access the workspace. Platform admins do not automatically receive access to another store's private APIs. Logout remains available to suspended users.

The Free product cap defaults to the proposed 100 via `FREE_PRODUCT_LIMIT` when the Free plan is first created. The catalog APIs now enforce active-product allowances per store; see [CATALOG_SETUP.md](CATALOG_SETUP.md). Paid billing remains a later feature.

## Password recovery and mail

Reset links use the saved user's role: owners/non-admin users go to `FRONTEND_URL/reset-password?token=...&email=...`; platform administrators go to `ADMIN_URL/reset-password?token=...&email=...`. Request origins and submitted redirects cannot replace those configured bases. Links expire after the configured broker lifetime (60 minutes by default); invalid or consumed links are rejected. A successful reset rotates the remember token, deletes the target user's stored sessions/API tokens, and invalidates session password hashes. Administrator resets also advance their session revision to reject older non-database sessions. See [admin password recovery](ADMIN_PASSWORD_RECOVERY_SETUP.md).

With `MAIL_MAILER=log`, local reset emails appear in `storage/logs/laravel.log`; no external email is delivered. Configure your own mail transport to enable delivery. Forgot-password responses intentionally do not disclose whether an address is registered or mail delivery failed; delivery errors are logged server-side.

## Platform administrators

No public admin registration or default admin password is provided. Provision a platform administrator manually with hidden password prompts:

```powershell
php artisan app:create-platform-admin
```

The independent admin frontend provides its own login and recovery pages on port 3001, using the shared session and password-broker APIs.

## Verification

```powershell
php artisan test
```

PHPUnit uses SQLite `:memory:`. Auth tests refuse to run database-refresh operations against another database. Coverage includes atomic registration, session cookies, CSRF, exact credentialed CORS, role/tenant isolation, suspension, rate limits, password boundaries, reset expiry/reuse, and session revocation.

Products, categories, inventory, cash POS checkout, and sales history are connected. See [CATALOG_SETUP.md](CATALOG_SETUP.md) and [POS_SETUP.md](POS_SETUP.md). Billing and Settings updates remain later features.
