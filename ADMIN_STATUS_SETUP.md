# Account and store status management

Platform administrators manage availability from **Admin -> Accounts / Stores -> details** on port `3001`. This feature changes account/store status only; administrators remain independent of store membership, and cannot use this feature to read private POS data.

## Workflow and hierarchy

1. Choose **Suspend** or **Reactivate** on the relevant details page.
2. Enter a reason (3-500 characters) and request a server review. Review does not change status.
3. Check the target, previous/new status, reason, and affected stores. Explicitly confirm to save.
4. Read the paginated status history for actor, date, reason, and previous/new status.

Suspending an account blocks its stores without rewriting their individual saved statuses. Reactivating it preserves stores that were separately suspended. Suspending one store affects that store only. A suspended store cannot be reactivated while its parent account is suspended; reactivate the account first. Reactivating an account with no active stores does not restore a usable workspace until a store is reactivated too.

Owner and membership restrictions continue to apply independently. Status management does not change a user's status, role, password, membership, subscription dates, financial records, stock, or balances. Paid periods retain their dates during suspension; this action does not pause or extend billing periods. There is no delete endpoint in this flow.

An open browser tab learns that access is unavailable on its next protected request; this feature does not introduce push notifications or erase previously loaded information. Existing sessions are checked on each request. A suspension is not a blanket logout of a user who may have another available workspace.

## API

All routes require an authenticated active platform administrator. Prefix the paths below with `/api/v1/admin/accounts/{account}` or `/api/v1/admin/stores/{store}`.

| Method | Suffix | Purpose |
| --- | --- | --- |
| POST | `/status-preview` | Review `status` (`active`/`suspended`) and required `reason` |
| POST | `/status-changes` | Confirm the exact `review_token` and UUID `request_key` |
| GET | `/status-changes/by-key/{key}` | Recover a confirmation for this target |
| GET | `/status-history` | Newest-first paginated immutable audit records |

Preview returns target metadata, previous/new status, reason, parent status for a store, impact counts, an encrypted token, and expiry. `impact.owner_access_blocked` accounts for the resulting account/store status, active owner and owner membership, and the presence of an active store. It can remain true after account reactivation if all stores are separately suspended. Tokens expire after ten minutes and bind the authenticated actor, target, status revisions, and reviewed proposal. The client cannot replace the target, actor, old status, or reason when confirming. Internal monotonic revisions detect a status that changed and then changed back after review; changed impact/owner eligibility requires another review too.

Confirmation returns the saved audit resource and `meta.replayed`, with HTTP 201 for a new change or 200 for its exact replay. Same-key replay is resolved before current-status or expiry checks. A reused key with different details, an already confirmed token under a different key, a stale/expired review, an unsupported transition, or an unavailable parent requires explicit handling; it does not silently create another change.

Audit responses expose saved target/actor names, IDs, status transition, reason, and date. History serialization uses snapshots and pagination rather than an actor/target relationship query per row.

## Transactions and recovery

The status update, revision increment, and audit insert commit together. Audit records are immutable. Status confirmations lock the account first, then the store where relevant. Owner workspace writes use `Services/Stores/StoreWriteAccess` inside the same transaction, taking that lock order and rechecking current account/store availability. A write admitted by middleware before suspension therefore cannot commit after the suspension unless it acquired the shared lock and completed first.

This check covers checkout, reversals, repayments, opening debt, customers, catalog, stock adjustments, cash opening/movements/closing, and store-profile updates. Read-only reports are not expanded into long write transactions. Adding another workspace mutation requires the same transactional guard before changing data.

The admin client retains the exact confirmation/token/key before POST, scoped to administrator and target. An uncertain result freezes that operation across navigation/reload in the same tab. Lookup and retry keep the original key. An unknown 404, network/5xx error, or CSRF preparation failure does not prove the write failed. A known final POST rejection permits a fresh review; it cannot be mistaken for a successful change. Clearing browser storage or closing the tab removes local recovery, so consult saved history before starting another operation afterward.

## Installation and verification

Run the additive status-management migration with `php artisan migrate`. It adds revision columns and an audit table; existing statuses and tenant data are preserved. Keep the installation's environment and application key.

```text
php artisan test
php vendor/bin/pint --test
composer test:mysql
```

From `admin`, run `npm test`, `npm run lint`, and `npm run build`. The default API suite uses guarded SQLite memory. The opt-in concurrency suite uses a separately marked disposable local schema; see [MySQL concurrency checks](docs/MYSQL_CONCURRENCY.md). No tests should suspend real owner accounts or reset the application database.
