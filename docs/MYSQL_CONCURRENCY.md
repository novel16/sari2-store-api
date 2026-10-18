# MySQL concurrency checks

`composer test` remains the default isolated SQLite suite. It does not run this suite or require MySQL. Run `composer test:mysql` explicitly to exercise InnoDB transactions in separate PHP processes. Do not run PHPUnit's parallel mode against one schema.

The suite covers seven scenarios: two checkouts competing for the last unit, two repayments reviewed at the same balance, two competing initial subscription activations, two competing renewals, final cash closing versus cash checkout, account suspension versus checkout, and store suspension versus checkout. Financial competition expects one commit and one domain conflict, then checks persisted stock, financial rows, subscription periods, or the final drawer snapshot. The closing race permits either a completed closing with checkout rejected, or a completed checkout with the closing review rejected as stale; it must never produce a closed drawer missing a committed cash sale. Suspension may commit after an already completed checkout; if suspension wins the shared lock first, the checkout must be forbidden. Later writes using previously active models must also be forbidden.

A parent connection holds the account row while both workers start transactions and attempt that same lock. Workspace mutations now lock account before store, matching status changes and subscription/catalog coordination. Both processes must be alive at that barrier before the lock is released. This tests service/transaction behavior; the default feature suite tests HTTP validation, sessions, and authorization.

## Disposable database only

Use an empty schema on a **local disposable MySQL server**. CI uses MySQL 8.4; use your deployment's MySQL version as an additional pre-release check. The suite accepts only loopback hosts, a schema named `sarisari_concurrency_test` or `sarisari_concurrency_test_<suffix>`, and an exact matching `MYSQL_TEST_ALLOW_RESET`. Connection values come exclusively from `MYSQL_TEST_*`, never the application's `DB_*` values. The first run refuses every nonempty schema without the harness's ownership marker. Subsequent runs reset only that marked schema with `migrate:fresh`.

Create the database and a user whose grants cover **only this schema**, using your local MySQL administration tool. Example SQL for a fresh disposable server (replace the example password locally):

```sql
CREATE DATABASE sarisari_concurrency_test_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'concurrency_runner'@'127.0.0.1' IDENTIFIED BY 'replace-with-local-test-password';
GRANT ALL PRIVILEGES ON sarisari_concurrency_test_local.* TO 'concurrency_runner'@'127.0.0.1';
```

From the API directory in PowerShell, with `pdo_mysql` and `proc_open` available:

```powershell
$env:MYSQL_TEST_HOST = '127.0.0.1'
$env:MYSQL_TEST_PORT = '3306'
$env:MYSQL_TEST_DATABASE = 'sarisari_concurrency_test_local'
$env:MYSQL_TEST_USERNAME = 'concurrency_runner'
# Set MYSQL_TEST_PASSWORD through your shell/secret manager; do not commit it.
$env:MYSQL_TEST_ALLOW_RESET = $env:MYSQL_TEST_DATABASE
composer test:mysql
```

The Linux equivalent exports the same six variables, then runs `composer test:mysql`. An empty password is accepted for an explicitly configured disposable local server. A cached application config is rejected; use a development checkout without `bootstrap/cache/config.php`. Do not clear a deployed installation's config merely to run tests.

Migrations run once per suite invocation. Each scenario creates its own owner/account/store and asserts only its fixture's records. The parent also observes both worker connection IDs executing `FOR UPDATE` in `SHOW FULL PROCESSLIST` before releasing the shared lock. The test user can inspect its own connections without the global `PROCESS` privilege; see the [MySQL process list documentation](https://dev.mysql.com/doc/refman/8.4/en/show-processlist.html).

The suite leaves its marked schema and synthetic fixtures for inspection. Remove that dedicated schema using your local database administration tool when finished. If a run is interrupted between migration and marker creation, create a new empty suffix schema; do not bypass the nonempty-schema guard. Running the suite without its required settings fails before any schema reset. The ordinary SQLite suite also tests guard rejection of application schema names, remote hosts, invalid ports, and missing reset consent.

## CI

Each project is a separate Git repository, so each has its own `.github/workflows/ci.yml`. The API workflow runs Pint and the full default suite, then the concurrency suite against a fresh MySQL 8.4 service under a schema-limited user. Client/admin workflows require their lockfile installation, `npm test`, lint, and production build. Configure branch protection to require these jobs in their respective repositories. These workflows validate changes; they do not deploy or access production credentials.
