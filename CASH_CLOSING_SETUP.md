# Daily cash closing

Cash Closing reconciles the store's physical cash drawer against recorded activity. This first version is owner-only, uses `Asia/Manila` calendar dates, and permits one final closing per store/date. The user approved final closings without reopening.

## Owner workflow

1. Open **Cash closing**, enter today's opening cash and optional note, then review and confirm the opening. Opening cash is the starting float **before today's recorded transactions**, not the current contents of the drawer. All of today's cash sales, cash utang payments, and cash refunds are included, even if they happened before opening this page.
2. Record additional cash-in or cash-out with a reason. These are physical drawer movements, separate from checkout, repayment, and refunds; do not record those transactions twice as manual movements. A cash-out cannot exceed the recorded expected cash available. Manual movements are allowed only for today's open cash day.
3. Count the cash and request a closing review. The server shows opening cash, each cash-flow component, expected cash, counted cash, and shortage/overage. A nonzero difference requires a note.
4. Explicitly confirm the review to save the final closing. The saved snapshot, actor, count, difference, and notes remain in history. There is no edit, delete, or reopen endpoint. An older open cash day can be closed from history; its movements cannot be backdated.

The formula is:

```text
expected cash = opening cash
              + cash sales after discounts
              + cash utang payments
              - cash refunds
              + manual cash-in
              - manual cash-out

variance = counted cash - expected cash
```

Positive variance is an overage; negative variance is a shortage. Utang sales and opening customer debt do not put cash in the drawer. Tendered cash is not revenue: cash sales contribute the sale total after change, not the amount handed over by the customer. Refunds affect their recording date, including refunds of an older sale.

Cash Closing does not require an opening record before ordinary POS transactions; this keeps existing store workflows usable. Once the current cash day is **closed**, new cash sales, cash repayments, and refunds paying cash are refused until the next Manila date. Utang-only activity remains available because it does not move cash. Replaying an already saved operation with its original key remains safe after closing or midnight.

## API

All paths below follow `/api/v1/stores/{store}` and require the active store owner's session. Platform administrators do not gain access to a private store cash drawer.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/cash-days/current` | Server business date and today's cash day/totals, or null when unopened |
| GET | `/cash-days` | Paginated cash-day history |
| POST | `/cash-days` | Open with `request_key`, expected `business_date`, `opening_cash_centavos`, optional `notes` |
| GET | `/cash-days/{cashDay}` | One authorized cash day and its live or saved totals |
| GET | `/cash-days/{cashDay}/movements` | Paginated manual drawer movements |
| POST | `/cash-days/{cashDay}/movements` | `request_key`, `direction` (`in`/`out`), `amount_centavos`, `reason` |
| POST | `/cash-days/{cashDay}/close-preview` | Read-only review from `counted_cash_centavos` and optional `notes` |
| POST | `/cash-days/{cashDay}/close` | Final confirmation with `request_key` and the exact `review_token` |
| GET | `/cash-days/by-key/{key}` | Recover an opening |
| GET | `/cash-days/movements/by-key/{key}` | Recover a manual movement |
| GET | `/cash-days/closings/by-key/{key}` | Recover a final closing |

Money input is integer centavos, bounded at 1,000,000,000 per opening, movement, or counted amount. Monetary totals are returned as decimal strings so the frontend can use exact integer arithmetic. Movement reasons require 3–255 characters; notes allow up to 500 characters. Store, actor, event timestamps, expected totals, and variance are calculated by the API.

Opening's `business_date` must be the date returned by `/cash-days/current`. It is an optimistic date check, not permission to backdate an opening. If midnight passes before an unsaved opening is submitted/retried, the API returns `cash_date_changed` and requires a fresh review for today. If that opening already committed, its same-key replay returns the original day first.

## Consistency and recovery

All cash-day writes share account-then-store locks with checkout, repayments, reversals, and admin status changes. Current workspace availability is rechecked under those locks. This serializes final closing against a cash transaction and prevents a write admitted before suspension from bypassing it. The business date and event timestamp are captured together under those locks. Tenant-scoped uniqueness and foreign keys protect cash days and their movements. Actor names and final totals are saved as historical snapshots; lists do not load an actor relationship for every row.

Closing reviews bind the actor, store, cash day, count, notes, and activity snapshot, and expire after ten minutes. A new cash event invalidates the review, including offsetting events whose net amount is unchanged. The owner must review again before confirming. Same-key replay is resolved before closed-day, date, or review-expiry checks, so a lost response never requires a fresh financial operation.

Before each financial POST, the client retains its exact request/key in session storage scoped to the current user and store. One unresolved opening, movement, or closing blocks another cash operation until lookup or identical retry confirms it. Read-only review requests do not create financial operations. Network/5xx results, unknown conflicts, CSRF failures, and lookup 404s retain recovery. A response received after leaving the page does not silently clear it. Browser storage errors block a new financial write. Closing the browser tab or clearing storage removes local recovery; saved records remain available in cash-day history.

## Installation and verification

Run the additive cash-day migration with `php artisan migrate`. It creates cash-day/movement tables and does not rewrite existing sales, payments, refunds, or balances. Keep existing application keys and environment settings.

```text
php artisan test
php vendor/bin/pint --test
composer test:mysql
```

The ordinary feature suite uses guarded SQLite memory. The opt-in MySQL suite requires its separate disposable schema; see [concurrency setup](docs/MYSQL_CONCURRENCY.md). Client verification runs `npm test`, `npm run lint`, and `npm run build` from `frontend`.

Important checks include cash-only totals, Manila midnight boundaries, stale/expired review handling, same-key replay after closing, tenant/admin restrictions, rollback, non-growing list query counts, explicit UI confirmation, and uncertain-response recovery across remounts. Closing is a reconciliation record; it does not transfer money or introduce automated billing.
