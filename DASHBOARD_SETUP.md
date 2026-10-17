# Store dashboard

The client dashboard at `http://localhost:3000/dashboard` reads `GET /api/v1/stores/{store}/dashboard`. It requires the existing Sanctum session, active user/workspace, and store-owner membership. A platform admin has no automatic access to store dashboards. Run pending API migrations, including reversal audit records; see [OPERATIONS_SETUP.md](OPERATIONS_SETUP.md).

## Periods and dates

The optional `period` accepts `this_week` (default), `last_week`, or `this_month`. Weeks start Monday. This week and month stop at today's Manila business date; last week covers the complete previous Monday–Sunday. Chart buckets include zero-sales days. The selected period affects the daily chart and top products only; cards and cash collections always describe today, while stock and outstanding credit show current balances. Recent transactions show the five latest completed sales across dates.

Database timestamps remain UTC. Boundaries are inclusive Manila midnight and exclusive next midnight, converted to UTC for indexed range queries. The response provides `timezone`, `business_date`, and `generated_at`; the browser formats dates in `Asia/Manila` regardless of the device timezone.

## Accounting definitions

- Today's sales: sum of completed sale totals after discounts, including cash and utang sales. This is sales revenue, not profit or cash on hand.
- Transactions: completed sales only; repayments and repeated idempotent requests do not create more transactions.
- Cash from sales: recorded amounts paid, excluding change returned to shoppers.
- Utang repayments: cash payment ledger entries created today, including collections for older sales.
- Total cash collected: cash from sales plus repayments. Repayments do not increase sales revenue.
- Outstanding utang: current customer balances across the store, including unpaid archived customers. It is not restricted to today's sales or the selected chart period.
- Stock: active products only. Low stock means positive quantity at or below the alert threshold; out of stock means zero. Alerts include both, ordered by quantity, name, then ID, with five returned.
- Top products: up to five products ranked by total saved sale-item quantities in the selected period. Completed cash and utang sales count; archived products retain their historical sales. Labels use the latest matching sale-item snapshot, not today's catalog name. Product ID breaks quantity ties consistently.

Aggregate money and units sold are returned as exact decimal strings. The client uses BigInt for chart totals and converts only bounded bar-height ratios to Number. Daily grouping is bounded to 31 SQL buckets and does not load transaction history into PHP. MySQL's default REPEATABLE READ transaction gives panels one consistent read snapshot without locking the store or stock rows.

## Client behavior

Dashboard pages/components live under `src/pages/dashboard`; typed requests and chart/date helpers live under `src/dashboard`. There are no sample figures, fabricated trends, or hardcoded dates. Requests cancel on period/store change or unmount. Loading replaces prior totals, failures provide Retry, and empty stores show genuine zero states and links to add products/start a sale. Refresh and revisiting the page load current records; no background polling or offline cache is used. Recent sale links open the saved sales-details dialog. Charts scroll within their panel on narrow screens and initially reveal the latest dates; daily figures are also available as a table. The workspace header/footer identify Dashboard as saved store records.

## Verification

From `api`:

```sh
php artisan test tests/Feature/Dashboard/DashboardTest.php
php artisan test
```

Tests require the guarded SQLite `:memory:` configuration in `phpunit.xml`; they do not reset the local MySQL database. Coverage includes actual checkout/repayment reconciliation, change and discounts, retries, midnight boundaries, period transitions, zero days, stock thresholds, historical snapshots, ranking, exact large balances, archived debt, and tenant/role/status restrictions.

From `frontend`:

```sh
node --test src/auth/auth.test.mjs src/catalog/catalog.test.mjs src/pages/pos/pos.test.mjs src/customers/repayments/repayments.test.mjs src/dashboard/dashboard.test.mjs
npm run build
```

Reports and CSV exports use the same `StoreActivity` calculations as Dashboard; see [REPORTS_SETUP.md](REPORTS_SETUP.md) for date ranges and accounting definitions. Today sales now deducts full-sale reversals on their recording date, and cash panels expose refunds and net cash collected. Original checkout charts/product rankings remain labeled before returns. See [OPERATIONS_SETUP.md](OPERATIONS_SETUP.md) for void/refund policy. Billing, partial refunds, staff accounts, profit accounting, and deployment remain separate work.
