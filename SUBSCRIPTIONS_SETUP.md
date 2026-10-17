# Manual Basic activation and renewal

Open Admin → Accounts → account details → **Manage subscription** on port 3001. Only active platform administrators can confirm a period. Administrators remain independent of the owner's account and store membership.

## Database

`2026_10_16_210000_create_subscription_activations_table` adds an immutable audit table without changing existing subscriptions or store records. It has already been applied to the local database. For another environment, run `php artisan migrate`.

Activation saves a new dated subscription with the matching `plan_version_id` and an audit record in one transaction. The audit snapshots the account name, plan version/terms, duration, exact period, amount/reference, reason, and administrator identity. Existing periods are retained. Publishing Basic alone does not activate or charge an account.

## Workflow and rules

1. Choose 1–12 calendar months of the published Basic offer.
2. Select verified manual payment or a complimentary grant. Paid records require the exact integer-centavo total, a unique reference, a verification checkbox, and a note. Complimentary grants require a reason, zero amount, and no payment reference.
3. Review the server-calculated dates, saved offer, amount, reference, and reason. No data changes during review.
4. Confirm the encrypted review and UUID request key. The review expires after five minutes. Its account, administrator, dates, and settlement cannot be replaced by client input.

Only published Basic with supported features can activate. Free requires no payment; Plus/Pro remain Coming soon. Accounts and owners must be active, with at most one registered store and no active non-owner members. An incompatible or open-ended paid period must be resolved separately before using this flow.

With no unexpired paid period, Basic starts at the review time. Renewal starts at the end of the last active/scheduled Basic period, preserving prepaid days. Calendar months use Asia/Manila with month overflow disabled (January 31 → February 28/29), and instants are stored in UTC. The end is exclusive: the next period becomes effective at exactly that instant. Renewal uses the currently published plan version; existing periods retain their own saved terms.

There are no automatic charges. `renews_at` remains null. Expiry is resolved from dates on each request; no scheduler is needed to downgrade. When no paid period covers now, Free allowances apply. No products, inventory, sales, or customer balances are removed. Existing operations continue above the Free cap; product additions/restorations wait until there is room.

## API

All paths below inherit `/api/v1/admin/accounts/{account}/subscription` and Sanctum, active-user, and platform-admin middleware.

| Method | Suffix | Purpose |
| --- | --- | --- |
| GET | none | Current terms, next scheduled period, count, and published Basic offer |
| GET | `/history?page=1` | Newest-first audit records, 20 per page |
| POST | `/preview` | Validate settlement and return a five-minute encrypted review |
| POST | `/activations` | Confirm the reviewed period atomically |

Preview input: `plan_id`, `months`, `settlement_method` (`manual_payment` or `complimentary`), `amount_centavos`, and `reason`. Paid requests also require `payment_reference` and accepted `payment_verified`. References are trimmed and uppercased; they allow letters/numbers plus `. _ : / -` and are unique across accounts. This records payment already verified outside the app; it does not collect money.

Confirmation accepts only the returned `quote` and a UUID `request_key`. The authenticated session supplies the actor. Exact retries return the original record, including after review expiry. Reusing a key for different details, confirming a review twice with different keys, or reusing a payment reference returns 409. Unique constraints protect concurrent requests across accounts. A changed plan version or subscription state requires a fresh review. New periods and the audit insert roll back together on failure.

The UI prevents double submission and saves the review/key to per-administrator/account sessionStorage before sending. After a connection failure or same-tab reload/navigation, it restores the pending review and offers Retry confirmation for that exact request. It clears recovery only after a saved or definitive result, and does not silently create a new grant. No credentials are stored. Closing the tab or clearing browser storage removes this recovery data; check history before starting another grant if that happens.

The owner endpoint `GET /api/v1/stores/{store}/subscription` adds `manual_activation_available` and `renewal` (manual mode, no auto-renewal, next period, scheduled count, last paid end). Billing checkout remains unavailable (`billing_available: false`). The owner screen shows expiry, upcoming saved terms, expired/expiring status, usage, and instructions to contact the service administrator. Private payment references, grant reasons, and administrator history stay in the admin API.

## Organization and verification

Admin controller, requests, resource, audit model, and subscription services live under `Admin`. Shared effective-plan/date status resolution stays under `Services/Plans`. Activation locks account → plan, matching catalog account serialization and SavePlan's plan lock. History renders saved snapshots without per-row relationship reads. Overview uses bounded aggregate/eager-loading queries; scaling tests compare requests with one and many scheduled periods.

```powershell
php artisan test --compact --filter="SubscriptionActivationTest|ManualSubscriptionLifecycleTest|SubscriptionOverviewTest"
php artisan test --compact
```

Tests cover authorization, exact settlement validation, grants, immutable snapshots, expiry/Free fallback, cash/utang/repayment access after expiry, Philippine month boundaries, scheduling, catalog changes, duplicate references, idempotent retries, tampered/stale reviews, rollback, and query scaling. The test database remains guarded SQLite `:memory:`.

Automatic gateway payments, self-service requests, cancellations/refunds of subscription payments, and Plus/Pro activation are separate future work. POS still uses Cash and Utang only.
