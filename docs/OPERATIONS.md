# Deployment, recovery, and performance

This is a repeatable operator runbook for this API and the independently versioned client/admin applications. Commands below are examples for a Linux server with a release directory, PHP/Composer, and MySQL tools already installed. Resolve the paths, database identity, service names, and release commits before using them. Nothing here runs as part of the application or CI deployment.

Feature behavior and financial/inventory invariants are documented in [catalog setup](../CATALOG_SETUP.md) and [operations setup](../OPERATIONS_SETUP.md).

## Prepare a release

1. Record the exact API/client/admin commit IDs and successful CI runs. Use `composer install` and `npm ci` with the committed lockfiles. Run the default API suite, Pint, both web builds/lints, and the opt-in MySQL concurrency suite on the intended MySQL version in an isolated test environment.
2. Build client/admin assets with their actual deployment API URL. Serve the API from `public/` through HTTPS; serve each web build with its SPA fallback. Use PHP production settings and writable `storage`/`bootstrap/cache`. Confirm trusted proxy settings when TLS terminates upstream.
3. Prepare a new API release directory. Connect its private production `.env` and persistent `storage` using your host's established secret/shared-storage mechanism. Preserve the **existing `APP_KEY`** and database credentials. Never run `key:generate`, `composer setup`, `migrate:fresh`, or `db:wipe` as an upgrade command. Existing encrypted subscription reviews and cookies depend on the key. Keep the key with the encrypted recovery secrets, separate from source control.
4. Verify `APP_ENV=production`, `APP_DEBUG=false`, API/client/admin URLs, exact CORS origins, Sanctum stateful hosts, HTTPS session settings, mail delivery, database, and backup access. Use the environment templates and [environment guide](../ENVIRONMENT_SETUP.md); review values without printing secrets to logs.

In the prepared release, install production dependencies:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

## Back up before migrations

Before the pre-release dump, put the served API into maintenance mode (`php artisan down --retry=60`) and stop/drain its queue workers so no POS writes continue between the snapshot and migration. Use a restricted backup account and a protected MySQL login path (configured interactively with `mysql_config_editor`). Do not put passwords in shell history or process arguments. Use a MySQL client compatible with the server. The following dump contains this application's tables without database-selection statements, so it can be restored into a different schema:

```bash
set -euo pipefail
database_name='REPLACE_WITH_VERIFIED_PRODUCTION_SCHEMA'
backup_path='/protected/backups/pos-before-release.sql'
test "$database_name" != 'REPLACE_WITH_VERIFIED_PRODUCTION_SCHEMA'
mysqldump --login-path=pos-backup --single-transaction --quick \
  --triggers --no-tablespaces --set-gtid-purged=OFF \
  --result-file="$backup_path" "$database_name"
test -s "$backup_path"
sha256sum "$backup_path" > "$backup_path.sha256"
```

`--single-transaction` provides a consistent InnoDB snapshot; do not run schema migrations/DDL during the dump. Pause POS writes and queue processing during the release cutover so the recovery point and migration boundary are clear. Record the backup timestamp, schema/server version, release commits, and aggregate counts/totals for sales, inventory, customer balances, ledger entries, and subscription periods. Back up persistent uploads, the current release manifest, and encrypted environment/recovery secrets too. Encrypt backups, copy them off-host, restrict access, and set retention from the acceptable recovery-point window. A successful dump alone is not a verified recovery.

## Apply and verify

Confirm the served API is still in maintenance mode, queue workers remain stopped/drained, and the backup is complete. Keep clients out of checkout until verification. In the new release with the preserved environment/storage:

```bash
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`PlanSeeder` creates missing launch plans and preserves plans already versioned/edited through the admin catalog. Review migration SQL/compatibility in staging first. Switch the server's release pointer using your deployment mechanism, reload PHP workers/OPcache, and restart queue workers against the new release (`php artisan queue:restart` signals Laravel workers; your supervisor still owns their lifecycle). Bring the served release up with `php artisan up` after smoke checks through the host's maintenance bypass or internal access.

Verify `/up` and `/api/v1/health`; those are liveness checks, not proof of database, mail, or transaction health. Then verify owner login/current workspace, admin login/metadata, catalog reads, and a controlled synthetic checkout/repayment/subscription review in staging. In production, use approved operational checks and inspect error logs/queue failures. Owner requests to foreign stores and owner requests to admin endpoints must still fail. Record the deployed three-repository manifest and monitor transaction conflicts, failures, and latency after reopening writes.

If deployment fails, keep writes paused. Repointing to the previous code is safe only if it supports the migrated schema. Do not run an unreviewed `migrate:rollback` over financial data. Prefer a compatible forward fix; if recovery requires restoring the pre-release snapshot, restore to a **new** schema, verify it, then deliberately switch the application connection. Writes accepted after the snapshot require reconciliation before reopening.

## Restore drill (separate environment)

Run this regularly and after material schema changes, not for the first time during an incident. Use a separate local MySQL instance/host and a schema-limited restore user. The source backup above must omit database-selection statements. Start from a verified backup and checksum; never import over the live application schema.

```bash
set -euo pipefail
restore_db='sarisari_restore_drill_20261017' # choose a new date/name for each drill
[[ "$restore_db" =~ ^sarisari_restore_drill_[0-9]{8}$ ]] || exit 1
dump_path='/protected/backups/pos-before-release.sql'
sha256sum --check "$dump_path.sha256"
# The pos-restore login path must point to the separate drill server.
# CREATE intentionally fails if this schema already exists.
mysql --login-path=pos-restore --execute="CREATE DATABASE \`$restore_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql --login-path=pos-restore --database="$restore_db" < "$dump_path"
mysqlcheck --login-path=pos-restore --check "$restore_db"
```

Use a separate checkout with a private environment targeting that drill schema, the backup's application key, mail delivery disabled/array, and no external queue consumers/webhooks. Run `php artisan migrate:status` to compare the backup to its recorded release; apply later migrations only when rehearsing that upgrade. Compare the recorded counts and financial aggregates with restored values. Check a sampled receipt, inventory movement history, customer ledger/balance, and immutable subscription activation/version terms through the application. Verify owner/admin authorization and that a synthetic transaction can complete in the drill. Confirm uploaded files can be retrieved. Record restore duration, data recovery timestamp, discrepancies, and the steps actually used. Retain the verified report; remove the drill schema and sensitive artifacts through the dedicated environment's administration process.

## Performance checks tied to this architecture

The existing query-scaling tests detect query growth, not production response time:

```bash
php artisan test --filter=QueryScaling
composer test:mysql
```

Before launch, seed synthetic data into a dedicated staging MySQL database at the expected scale (products, sales/items, customers/ledger entries, subscription history). Warm normal application/config caches and measure dashboard, product lookup/list, sales report, customer statement, and admin directory requests. Record row counts, response size, query count/time, database version, machine size, and p50/p95 latency with 1, 5, and 10 concurrent clients. Run writes only against that staging dataset, using unique request/idempotency keys per intended operation and retrying the same key after an ambiguous timeout.

The services deliberately serialize workspace mutations on account-then-store rows, coordinating with account/store suspension and subscription/catalog changes. Measure **one busy store** and **one busy account** separately from independent accounts; otherwise a pooled benchmark can hide lock bottlenecks. Confirm last-unit losers receive conflicts, repayments require balance re-review, competing subscription reviews do not create overlapping periods, and suspension prevents subsequent workspace writes. Track deadlocks/lock wait timeouts and failed requests alongside successful throughput. Review slow queries with `EXPLAIN` and representative filters before adding indexes or replacing those correctness locks. Establish a latency/error target from actual store traffic and keep the measured baseline with the release manifest; no production capacity claim follows from passing SQLite tests.

References: [Laravel 12 deployment](https://laravel.com/docs/12.x/deployment), [MySQL 8.4 mysqldump](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).
