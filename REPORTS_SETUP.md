# Sales and collections reports

Open `http://localhost:3000/reports`. Reports use the current store's saved transactions. Run pending API migrations, including the reversal audit migration described in [OPERATIONS_SETUP.md](OPERATIONS_SETUP.md). The independent admin app stays on port `3001`.

## API and authorization

`GET /api/v1/stores/{store}/reports/sales?date_from=2026-10-12&date_to=2026-10-16`

Both dates are required ISO `YYYY-MM-DD` values. The start must be on/before the end, the inclusive range must be at most 366 days, and supported dates are `1970-01-01` through `9998-12-31`. Invalid or array-valued dates return validation errors (422). Date ranges are interpreted in **Asia/Manila**, independently of the browser/server display timezone. Queries use inclusive start midnight and exclusive midnight after the end date, converted to UTC.

The endpoint uses existing Sanctum session, active-user/workspace, and owner/store membership middleware. Unauthorized store access returns 404; platform admins do not receive automatic access to private reports. Query/body store IDs cannot override the authorized route store.

The `data` response contains store ID/name, dates, timezone, `generated_at`, `summary`, every daily bucket in `days`, and `top_products` with a maximum of ten (`top_products_limit`). Empty days and empty stores have real zeros. Amounts and unit totals are exact decimal strings; transaction and repayment counts are integers.

## Definitions

- **Gross sales**: saved subtotals of completed cash and utang sales, before order discounts.
- **Discounts**: discounts recorded on those completed sales.
- **Sales after discounts**: saved completed sale totals; gross sales minus discounts.
- **Transactions / units sold**: completed sales and saved quantity totals; repayments are not sales. Fully discounted/free completed sales still count.
- **Average sale**: sales after discounts divided by transactions, rounded half up to the nearest centavo; zero when no transactions exist.
- **Cash sales**: amounts paid toward completed cash sales, excluding change returned.
- **Utang sales**: credit amounts from completed credit sales; included in sales revenue, not cash collected.
- **Cash utang repayments**: cash payment ledger entries created within the date range, including payments against older debt and archived customers. Charges do not count as collections.
- **Total cash collected**: cash sales plus cash utang repayments. Collections do not add another sale and do not represent profit/cash-drawer closing balance.
- **Top products**: ten products ranked by summed saved sale-item quantities, ties broken by product ID. Archived products retain history. Product names/units use the latest matching sale-item snapshot; amounts use saved line totals **before order discounts**. Discounts are not allocated to products.

`StoreActivity` supplies the shared sales/collections calculations for Reports and Dashboard. Reports wrap summary/days/ranking reads in a transaction; MySQL's default REPEATABLE READ gives one consistent read snapshot without locking stock/store rows. Queries aggregate in SQL and return at most 366 daily buckets/ten products; transaction history is not loaded into PHP. Dashboard keeps its existing response shape, today cards, and chart/top-five behavior.

## Client and CSV

Default is **This week**, Monday through today. Presets include Today, This week, and This month. Editing either date selects Custom range. Apply dates validates and loads the range; unsaved dates disable export and refresh, with applied dates always displayed above the report. Loading/errors hide earlier figures; Retry and Refresh load current records. Requests are cancelled on filter/store change or unmount. There is no background polling or offline cache.

The chart switches between sales and cash collected. Tap/hover a day or use the accessible day selector to inspect exact figures. The Daily figures table includes every day and supports horizontal scrolling on narrow screens.

CSV exports the **already displayed API response**, with its dates and generation time; exporting never refetches newer totals. It contains store/date metadata, summary, cash/credit/repayment breakdown, all daily rows, and top-ten quantities/gross product sales. It does not include customer contacts, customer notes, or detailed credit ledgers. Values use exact peso decimals (BigInt conversion), CSV quotes/BOM, and shared spreadsheet-formula protection for user-entered names. Changing dates or refreshing clears the previous exported notice. The filename includes store ID and the applied range.

## Files

- `app/Services/Reports/StoreActivity.php`: shared exact aggregations and indexed UTC date filters.
- `app/Services/Reports/SalesReport.php`: report snapshot composition.
- `app/Http/Requests/Reports/SalesReportRequest.php`, `SalesReportController.php`, `routes/api.php`: validated store-scoped read API.
- `app/Services/Dashboard/DashboardSummary.php`: delegates common calculations while preserving existing Dashboard payloads.
- `frontend/src/reports/`: types, API, date/money helpers, CSV export, and tests.
- `frontend/src/pages/reports/`: small page wrapper, workspace, filters, cards, chart, breakdown, ranking, and daily table components.
- `frontend/src/config/navigation.ts`: identifies Reports as live saved store records.

## Run and verify

Keep the normal API and frontend dev servers on ports `8000`/`3000`. No migrations or dependency install is needed for this change.

From `api`:

```sh
php artisan test tests/Feature/Reports/SalesReportTest.php
php artisan test
```

The existing test guard requires SQLite `:memory:`; tests never reset the local MySQL database. Coverage includes real checkouts and repayment retries, Dashboard reconciliation, collection-only days, midnight cutoffs, archived debt, saved snapshots, top-ten ranking, exact large units, zero/free sales, leap years, invalid dates, range limits, and tenant/role/status restrictions.

From `frontend`:

```sh
node --test src/auth/auth.test.mjs src/catalog/catalog.test.mjs src/pages/pos/pos.test.mjs src/customers/repayments/repayments.test.mjs src/dashboard/dashboard.test.mjs src/reports/reports.test.mjs
npm run build
```

Full-sale voids/refunds now appear as event-date offsets in the report's reversal/net section and daily CSV, while original checkout figures remain intact; see [OPERATIONS_SETUP.md](OPERATIONS_SETUP.md). Profit/expenses, product discount allocation, historical stock balances, PDF/tax reports, partial refunds, staff permissions, billing, and deployment remain separate work.
