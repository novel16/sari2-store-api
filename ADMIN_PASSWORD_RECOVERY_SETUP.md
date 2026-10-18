# Administrator password recovery

The admin login at `http://localhost:3001/login` links to **Forgot password?**. Recovery uses public `/forgot-password` and `/reset-password` SPA pages, backed by the existing shared Laravel password broker. There is no separate administrator credential store, public admin registration, or reset-token table.

## Local and deployed URLs

The API chooses the email link from the user's saved role:

| API configuration | Local default | Recipient |
| --- | --- | --- |
| `FRONTEND_URL` → `app.frontend_url` | `http://localhost:3000` | Store-owner/non-admin user |
| `ADMIN_URL` → `app.admin_url` | `http://localhost:3001` | Platform administrator |

Each configured base gets `/reset-password?token=...&email=...`, with query values encoded. The request origin and submitted redirect/role fields cannot override that destination. Admin and owner email links therefore return to their own app even when requested from the other app. Keep the configured bases consistent with the deployment's SPA origins and Sanctum/CORS setup.

Example API local settings:

```dotenv
FRONTEND_URL=http://localhost:3000
ADMIN_URL=http://localhost:3001
MAIL_MAILER=log
```

The existing private local environment, key, credentials and database are preserved; localhost defaults work without overwriting `.env`. Credential-free local/development/production templates now include `ADMIN_URL`. On a deployed installation, set its actual owner/admin HTTPS URLs and rebuild configuration using its normal deployment procedure. No database migration is required for this feature; the existing administrator-management migration supplies the session revision column.

## Recovery behavior

1. Submit an email address. The success message remains generic for registered, unknown, and broker-throttled addresses; mail delivery failures also do not reveal account existence.
2. Open the mailed link. The admin page rejects incomplete/duplicate/malformed email or token parameters without making a reset request. Opening a link never resets a password automatically.
3. Enter and confirm a password: at least 8 characters, at most 72 UTF-8 bytes, and no null byte. The server remains authoritative. Current policy permits reusing the same password; the new hash and administrator revision still revoke prior access.
4. A successful reset consumes the token, clears the admin page's token query and volatile password inputs, and finishes browser logout/session verification before returning to admin login with a success notice. It does not log the user in automatically. If completion fails, **Continue to sign in** retries only sign-out/verification, never the password reset.

Recovery pages remain reachable with an existing owner/admin session. The shared API cookie means successful UI completion signs out any account currently in that browser; the screen states this explicitly. The API itself never switches an existing caller's identity to the reset target. Resetting a different user revokes only the target's credentials/sessions; the UI's explicit browser logout is a separate completion step.

A lost reset response may occur after the password was saved. The UI explains that uncertainty and offers sign-in with the new password or a fresh reset link. It does not automatically replay network/5xx failures. The existing HTTP client retries one explicit CSRF 419 after obtaining a new cookie. A used token cannot be reset again.

## Server guarantees and boundaries

- The standard broker stores only the token hash, expires links after the configured lifetime (60 minutes by default), and throttles issuance per email (60 seconds by default).
- `/forgot-password` and `/reset-password` share the existing five-requests-per-minute IP limit and CSRF/session protections. Malformed input returns 422, invalid/expired/consumed tokens return generic link errors, and throttled requests return 429.
- `ResetUserPassword` locks the target user and rechecks the token inside the transaction before changing credentials. It rotates the remember token, deletes target database sessions and Sanctum tokens, and consumes the reset token. A successful administrator reset also increments `admin_status_revision`, so older file/cache/cookie sessions cannot survive by retaining an old payload. Other users are unaffected.
- Resetting a suspended administrator leaves the role and suspension unchanged; login remains forbidden. Recovery creates no account, membership, or store, and does not grant access to private POS endpoints.
- Passwords/tokens are never stored by the SPA in local/session storage or router state. The admin HTML uses `no-referrer` before subresource links; used query parameters are removed after confirmed success. Success navigation contains only a boolean reset notice.

`PasswordResetUrl` and broker/reset behavior stay under shared `Services/Auth`, because the same rules serve both apps. Administrator authorization and management remain in their existing `Admin` namespaces. SPA pages compose small recovery components, parsing/validation helpers, and a reset/completion hook; `App.tsx` stays unchanged. The auth provider's optional strict refresh is used to detect completion verification errors while preserving existing callers' behavior.

## Mail delivery

`MAIL_MAILER=log` is suitable for local development: the email and reset link are written to the API's private `storage/logs/laravel.log`. It does not send an inbox email. Keep logs and reset links private.

Actual delivery needs a working SMTP/provider configuration. Use the existing production template's `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`, filled from that deployment's mail service. Set the scheme/port required by that provider; do not commit credentials. No external email was sent during automated validation.

## Verification

```powershell
php vendor/bin/pest --filter="AdminPasswordRecoveryTest|PasswordResetTest|AuthRateLimitTest|PasswordInputSecurityTest|EnvironmentTemplatesTest" --compact
php vendor/bin/pint --test
```

Admin `npm test`, lint and build cover real mounted forms, API payloads, password rules, malformed links, generic responses, sanitized errors, bounded CSRF retries, ambiguous outcomes, token-query retirement, and completion retry. API tests cover trusted role-based URLs, hashed/expired/reused tokens, active and suspended admins, target-only revocation, revision checks, existing signed-in recovery, unchanged owner behavior, and environment-template consistency.
