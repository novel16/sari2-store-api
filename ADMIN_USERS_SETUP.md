# Platform administrator management

Open **Administrators** in the admin sidebar on port `3001`. Administrators remain shared `User` records with `platform_role=platform_admin`; creation adds no account, store, or owner membership. Existing owners cannot be promoted by entering their email address in this flow. There is no public administrator signup, delete, or role-edit endpoint.

## Bootstrap and create

Create the first administrator through the existing interactive command:

```text
php artisan app:create-platform-admin
```

The command retains hidden password/confirmation prompts and explicit confirmation. It now records an immutable creation event with Console as actor, using the same roster lock as the web flow. It supplies no default credentials.

An active administrator can then use **Administrators -> Create administrator**:

1. Enter name, unused sign-in email, and reason (3-500 characters).
2. Review the server's normalized details. Review is read-only and expires after ten minutes.
3. Enter the new administrator's password/confirmation and your own current password; explicitly confirm creation.
4. The user and immutable creation event commit together. The new administrator signs in through the separate admin login. This feature does not send email or create an invitation link.

Passwords follow the existing 8-72 character/maximum 72 UTF-8 byte policy. Neither plaintext credentials nor password hashes are put in review tokens, audit snapshots, request fingerprints, or browser recovery storage. Credentials are transient confirmation inputs, while normal password hashing remains on the server.

## Status and access

The directory supports search by name/email, active/suspended filters, and pagination. Open details to see saved metadata and paginated creation/status history. Choose suspend/reactivate, enter a reason, review the current status and active-administrator count, then confirm with your current password.

Self-suspension is refused. The last active administrator must remain active. Roster mutations serialize on one database row before locking the actor and target users in ID order. If two administrators try to suspend each other concurrently, the loser must fail once its own access is revoked; both cannot become suspended. Status revisions detect changes that return to the previous status after review. A changed roster requires a fresh status review.

Suspension rotates the target's remember-me token, removes its stored database sessions, revokes Sanctum tokens and reset links, and increments its admin revision. The login/session revision check also prevents older file/cookie/cache-backed sessions from regaining access after reactivation. Reactivated administrators sign in again. Valid remember-me restoration uses the current token; suspension invalidates old recallers.

[Password recovery](ADMIN_PASSWORD_RECOVERY_SETUP.md) also advances this administrator revision after a successful reset, revoking previous sessions without changing role or availability. A suspended administrator can replace credentials but remains suspended.

Previously admitted privileged writes recheck the actor under a user lock, including plan changes, subscription activations, account/store status changes, and profile/password updates. They cannot rely solely on earlier middleware authorization. Account/store restrictions remain separate; suspending a platform administrator does not suspend any store account or alter private financial records.

## Recovery without stored passwords

Before confirming, the client saves only the exact request key and non-secret review in session storage, scoped to administrator and operation. A lost response freezes that operation across navigation/reload in the same tab. Lookup checks the original actor-scoped key without requiring passwords. If no result is found yet, retry retains the same key and review and requires credentials to be entered again; it checks for a saved result before posting.

The operation fingerprint binds actor, operation, target, and review token, rather than credentials. A replay of a committed creation returns its original saved result and never changes the created user's initial password, even if different initial credentials were entered for recovery. Password proof is still required on a confirmation POST; lookup is the preferred way to resolve a lost response. Credential-validation errors retain recovery too: a failed password on a retry cannot prove that an earlier request did not commit. Unknown conflicts, network/5xx errors, CSRF failures, and lookup 404s retain recovery. Known business rejections permit a fresh review. Closing the tab or clearing storage removes local recovery; inspect saved history before creating another operation afterward.

## My profile and password

**My profile** edits the current administrator only. Email changes require current-password proof and revoke other sessions/tokens/reset links. Password changes require current-password proof, matching confirmation, and a different new password; other devices are signed out while the current session continues. Name-only changes preserve sign-in sessions. These screens reuse shared settings rules through an admin-specific controller/service, without creating a parallel authentication system.

## API and organization

All routes below inherit `/api/v1/admin`, authenticated active-user and platform-admin middleware. Mutations are CSRF-protected and rate-limited.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/administrators` | Searchable paginated metadata plus active-administrator count |
| GET | `/administrators/{administrator}` | Platform administrator metadata and active count |
| POST | `/administrators/creation-preview` | Review name/email/reason without credentials |
| POST | `/administrators` | Confirm review/key with current password and initial password/confirmation |
| POST | `/administrators/{administrator}/status-preview` | Review status/reason |
| POST | `/administrators/{administrator}/status-changes` | Confirm review/key with current-password proof |
| GET | `/administrators/operations/by-key/{key}` | Recover this actor's immutable saved operation |
| GET | `/administrators/{administrator}/history` | Paginated immutable creation/status events |
| PATCH | `/profile` | Update own profile |
| PATCH | `/password` | Change own password |

Creation/status responses contain allowlisted `data`, immutable `event`, and `meta.replayed` (201 new, 200 replay). Audit snapshots preserve target/actor names, target email, action, previous/new status, reason, and date without per-row relationship queries. The new service/model/requests/resources/controllers live in `Admin` namespaces; frontend modules live under `administrators` and `settings`. Shared authentication, user models, and password policy remain shared.

## Installation and checks

The additive `2026_10_18_120000_create_administrator_management` migration adds the user revision, singleton roster lock, and administrator audit table. It creates no administrator or store, and does not rewrite existing user statuses. Apply it with `php artisan migrate` while preserving the installation's environment and application key.

```text
php artisan test
php vendor/bin/pint --test
composer test:mysql
```

Run `npm test`, `npm run lint`, and `npm run build` in `admin`. Ordinary API tests use guarded SQLite memory; real races use the separate marked disposable schema described in [concurrency setup](docs/MYSQL_CONCURRENCY.md). Tests must not provision or suspend real administrators in the application database.
