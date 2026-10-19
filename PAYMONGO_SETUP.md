# PayMongo QR Ph subscription payments

Owners buy or renew Basic from `http://localhost:3000/subscription`. They review the current server-priced offer, open PayMongo Hosted Checkout, and return to the subscription page to check confirmation. There is no owner subscription-request approval step. Admins monitor orders at `http://localhost:3001/subscription-payments`; existing reviewed manual grants remain available separately.

## Webhook URL to register

Register this public **POST** endpoint in the matching PayMongo Test or Live dashboard:

```text
https://YOUR-NGROK-DOMAIN/api/v1/webhooks/paymongo
```

Local route: `POST http://localhost:8000/api/v1/webhooks/paymongo`.

Subscribe to **`checkout_session.payment.paid`**. The callback uses the webhook endpoint's signing secret. It does not need an owner login, cookies, a CSRF token, or a frontend Origin. Only this exact API path is exempt from browser CSRF validation; ordinary authentication and subscription writes retain their CSRF protection.

The backend verifies `Paymongo-Signature` against the original request bytes, its timestamp, the configured Test/Live mode, and the endpoint signing secret. The timestamp tolerance is 300 seconds; keep the server clock synchronized. Signature verification happens before parsing the payment event. Unsigned callbacks return 401 and cannot create a paid order.

## API configuration

Configuration is isolated in `config/paymongo.php`. Laravel's HTTP client handles PayMongo's API directly; this implementation adds no provider SDK dependency.

Set these values in the API installation's existing private environment file. Empty values below are intentional; use your own credentials locally and never commit them.

```dotenv
PAYMONGO_ENABLED=true
PAYMONGO_MODE=test
PAYMONGO_SECRET_KEY=
PAYMONGO_WEBHOOK_SECRET=
```

- `PAYMONGO_SECRET_KEY`: existing server API secret, beginning with `sk_test_` in Test mode or `sk_live_` in Live mode.
- `PAYMONGO_WEBHOOK_SECRET`: the **endpoint signing secret** from the webhook just registered. This is separate from the API secret key.
- `PAYMONGO_MODE`: `test` or `live`; the secret-key prefix and verified event mode must agree.
- `PAYMONGO_ENABLED`: controls creation of new checkouts. Set `false` to stop new purchases while retaining verification and recovery of already-issued payments.

Hosted Checkout does not use `PAYMONGO_PUBLIC_KEY`. An existing private value may remain in place, but no PayMongo key belongs in a `VITE_*` variable or frontend environment. The owner app receives only its hosted checkout URL and safe order status.

Checkout availability requires enabled billing, a mode-matching API secret, and a nonempty webhook signing secret. Merely adding the API keys does not enable an unsafe checkout without webhook configuration. The API origin is fixed to `https://api.paymongo.com`; connection/response timeouts are 5/15 seconds. QR Ph is the only enabled method and `pass_on_fees` is false, so the saved order amount is the checkout amount.

Keep `FRONTEND_URL` pointed at the owner app (`http://localhost:3000` locally); it determines trusted return URLs. An API ngrok tunnel does not require changing browser CORS/Sanctum domains or putting ngrok's URL into `FRONTEND_URL` when the owner app stays local.

After changing local configuration:

```powershell
php artisan config:clear
php artisan queue:restart
```

Restart any manually launched queue process if necessary. On a deployed installation, rebuild the configuration cache and restart workers through the existing deployment process. Preserve the current `APP_KEY`: checkout URLs and durable webhook data are encrypted with it.

## Database and background processing

```powershell
php artisan migrate
php artisan queue:work --tries=5 --timeout=30
```

Run the API, frontend, queue worker and scheduler in separate terminals or managed processes. For the local scheduler:

```powershell
php artisan schedule:work
```

`2026_10_18_180000_create_subscription_payment_orders` creates `subscription_orders` and `paymongo_webhook_events`. It adds records alongside existing subscriptions without rewriting old periods or POS transactions. No plan prices are seeded or changed by this migration.

Use a durable queue connection, such as the existing database queue. The callback first commits an encrypted webhook inbox entry, then dispatches a job containing its database ID. A 200 callback means the event is accepted durably; the order can remain pending briefly while its job runs. Queue dispatch failure returns 503 and leaves the inbox entry available for retry. Repeated events/jobs cannot grant another period for the same order/payment.

`billing:reconcile` is scheduled every minute with overlap protection. It retries stored verified payment events and paid-but-unfulfilled orders, and explicitly expires overdue unpaid checkouts when the provider confirms expiration is safe. It never invents a paid receipt from a GET response. A missed callback must be delivered by PayMongo; reconciliation cannot reconstruct its signature.

Manual reconciliation:

```powershell
php artisan billing:reconcile
php artisan billing:reconcile --order=YOUR-ORDER-UUID
```

## Order and coverage rules

| Situation | Backend behavior |
| --- | --- |
| First purchase or expired paid coverage | Start the purchased period when fulfillment succeeds |
| Existing contiguous Basic coverage | Append after all existing prepaid Basic periods |
| Catalog changes after checkout | Preserve the purchased plan version, price and allowances |
| Duplicate callback or retry | Retain one payment and one granted period |
| Browser returns with `payment=return` | Display/check the stored order; do not mark paid |
| Owner closes the browser | The webhook and worker can still activate the purchase |
| Paid, but account/owner/store is unavailable | Keep payment recorded and flag `needs_attention`; do not reactivate suspended access |
| Temporary activation failure | Keep the verified paid fact and retry fulfillment without charging again |
| Unknown checkout-creation result | Keep the account's unresolved payment slot; do not create another payable checkout |

Amounts are integer PHP centavos and come from the database, not a browser-submitted total. A paid webhook must match the order UUID in both reference and metadata, the checkout session, PHP currency, gross amount, QR Ph source and Test/Live mode. The database enforces unique request keys, checkout IDs, payment IDs and subscription grants. Account-first transaction locks coordinate with manual renewals and account suspension.

Payment and fulfillment are separate states. Payment states are `creating`, `pending`, `unknown`, `paid`, `expired` or `failed`; fulfillment is `pending`, `fulfilled` or `needs_attention`. A paid order that needs attention blocks further purchases until safely fulfilled. If its callback arrived after a replacement checkout was created, its receipt is recorded without taking that newer checkout's slot. Admin monitoring shows the states and issue code separately.

Periods use Philippine calendar months without overflow and store instants in UTC. Renewals are one-time purchases, with no recurring bank debit. Free fallback after coverage expiry and existing cash/utang POS behavior remain unchanged.

A QR's expiry does not automatically expire its Checkout Session. This application requests provider expiration after the configured local 30-minute lifetime. Leaving/cancelling the browser does not prove cancellation. A slot is released only after confirmed safe provider expiry; a paid, pending or processing payment remains unresolved until its signed callback is handled. Resume the existing checkout while it is still payable.

## Recover a lost checkout response

If creation times out after PayMongo accepted it, find the existing Checkout Session in the matching PayMongo dashboard by the application's order UUID/reference. An operator can bind that verified existing session:

```powershell
php artisan billing:reconcile --order=YOUR-ORDER-UUID --session=cs_EXISTING_SESSION_ID
```

The server retrieves the given ID, verifies its returned ID, order reference, metadata and mode, and preserves any already-linked session. It does not create a replacement and does not mark the order paid. If the dashboard shows payment but no signed callback reached this server, request a genuine PayMongo redelivery to the registered endpoint. Do not change the order/payment status manually or create a second checkout to resolve an unknown result.

## Owner and admin API

All owner paths below start at `/api/v1/stores/{store}/subscription` and require the authenticated active account owner and workspace access.

| Method | Suffix | Purpose |
| --- | --- | --- |
| GET | none | Effective plan, offers, billing readiness and unresolved order |
| GET | `/orders` | Paginated payment history with linked coverage |
| POST | `/orders` | Create the reviewed current offer with a UUID request key |
| GET | `/orders/by-key/{key}` | Recover the exact purchase after an ambiguous browser result |
| GET | `/orders/{order}` | Read one account-scoped order by UUID |
| POST | `/orders/{order}/expire` | Ask the server to safely expire its existing provider session |

Create accepts `request_key`, `plan_id`, `expected_plan_version` and `months` (1-12). Server-owned payment, total, session, coverage and fulfillment fields are prohibited. Retrying the same key and details returns the same order. A changed offer requires a new review; an unresolved account payment must be handled before a different purchase is created.

`GET /api/v1/admin/subscription-payments` requires a current active platform administrator. It supports `q`, `payment_status`, `fulfillment_status`, `page` and `per_page`, with account/owner/subscription relations loaded in fixed batches. It exposes safe order/account details and coverage without the checkout URL, raw webhook, provider credentials or stored idempotency hashes. This is a read-only directory; it does not approve payments or set paid status.

## User-run ngrok and PayMongo test

The user runs ngrok, configures PayMongo and performs external payment tests. Automated repository tests use `Http::fake()` and do not contact PayMongo. Both PHPUnit configurations disable billing and clear PayMongo credentials by default; payment tests explicitly supply fake configuration, so a developer's private keys cannot affect test results.

1. Start the API on 8000 and keep owner/admin apps on 3000/3001. Run the queue worker and scheduler above.
2. Run `ngrok http 8000` in your own terminal and copy its HTTPS forwarding domain.
3. In the PayMongo **Test** dashboard, register `https://YOUR-NGROK-DOMAIN/api/v1/webhooks/paymongo` and select `checkout_session.payment.paid`.
4. Save that endpoint's signing secret to the API's private `PAYMONGO_WEBHOOK_SECRET`. Set matching Test credentials/mode and enable billing, then reload configuration/workers.
5. Open the owner Subscription page, review Basic and proceed to Hosted Checkout. Use PayMongo's supported test simulation; observe its genuine signed delivery, inbox/job processing, paid order and resulting coverage.
6. Try a duplicate delivery and a browser close/return. The same payment must still grant only one period. Returning to the app before the worker finishes should display pending rather than assume payment succeeded.
7. Dashboard **Test Events** can verify endpoint reachability and signatures. A generic sample event with an unrelated order reference is accepted/ignored and must not activate a real application order. For order validation, use the order/session/payment details from your own test checkout.

**QR Ph test detail:** PayMongo's current testing guide says QR Ph can generate real QR codes even in Test mode. Do not scan and pay a test QR with a banking app. Use the provider's `test_url` simulation or Dashboard Test Events instead. See [PayMongo payment testing](https://docs.paymongo.com/docs/payment-acceptance-testing) and [webhook testing](https://docs.paymongo.com/docs/webhook-testing).

If ngrok supplies a new domain, update the registered webhook endpoint. The backend route remains the same. Do not expose keys, signing secrets, checkout URL fragments or raw payment payloads in screenshots, shared logs or chat.

## Debugging and local verification

| Symptom | Check |
| --- | --- |
| Checkout unavailable | Enabled flag, matching secret-key/mode and configured endpoint signing secret; clear cached config and restart workers |
| Webhook 401 | Correct endpoint secret, Test/Live signature slot, unchanged raw body and server clock within 300 seconds |
| Webhook 400 | Valid documented event envelope and matching boolean mode |
| Webhook 409 | Same event identity arrived with different bytes; inspect provider delivery identities without overwriting evidence |
| Webhook 503 | Missing signing configuration or queue dispatch failure; inbox evidence remains for recovery when accepted |
| Pending after return | Inspect PayMongo delivery result, durable inbox, running worker and failed jobs; the return URL is not payment proof |
| Paid / needs attention | Resolve the recorded account/coverage issue, then retry reconciliation; do not pay again |
| Unknown creation | Recover the existing provider session by order reference; never blindly retry remote creation |

Application diagnostics use `billing.*` messages containing safe order/event identifiers, operation names, status codes, issue codes or exception class names. Provider bodies, credentials and Authorization headers are excluded. Keep production `APP_DEBUG=false` and restrict operator access to logs and queue failures.

```powershell
php vendor/bin/pest tests/Feature/Payments --compact
php vendor/bin/pint --test
```

Focused coverage includes fake HTTP creation/retrieval/expiration, raw signatures and mode separation, current-offer pricing, duplicate/replayed events, rollback/recovery, immutable terms, Philippine dates, access changes, CSRF isolation, owner/admin scoping and safe serialized data. Passing these checks does not replace the user-run PayMongo/ngrok integration test.

References: [Hosted Checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout), [quick start](https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start), [webhook setup/signatures](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management), [checkout lifecycle](https://docs.paymongo.com/docs/payment-channels-key-concepts), [payment-channel testing](https://docs.paymongo.com/docs/payment-channels-testing).
