<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MySqlConcurrencyDatabase
{
    private const MARKER = '_sarisari_concurrency_guard';

    public static function environment(): array
    {
        $environment = [];
        foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD', 'ALLOW_RESET'] as $key) {
            $name = 'MYSQL_TEST_'.$key;
            $environment[$name] = (string) ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name) ?: '');
        }

        return $environment;
    }

    public static function settings(array $environment): array
    {
        $database = $environment['MYSQL_TEST_DATABASE'] ?? '';
        if (strlen($database) > 64 || ! preg_match('/\Asarisari_concurrency_test(?:_[a-z0-9_]+)?\z/D', $database)
            || ($environment['MYSQL_TEST_ALLOW_RESET'] ?? '') !== $database) {
            throw new RuntimeException('Provide a sarisari_concurrency_test[_suffix] database and identical MYSQL_TEST_ALLOW_RESET consent.');
        }
        if (! in_array($environment['MYSQL_TEST_HOST'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || empty($environment['MYSQL_TEST_USERNAME'])) {
            throw new RuntimeException('MySQL concurrency tests require explicit loopback MYSQL_TEST_HOST and MYSQL_TEST_USERNAME.');
        }
        $port = $environment['MYSQL_TEST_PORT'] ?? '3306';
        $port = $port === '' ? '3306' : $port;
        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('MYSQL_TEST_PORT must be a valid TCP port.');
        }

        return ['driver' => 'mysql', 'url' => null, 'host' => $environment['MYSQL_TEST_HOST'],
            'port' => $port, 'database' => $database, 'username' => $environment['MYSQL_TEST_USERNAME'],
            'password' => $environment['MYSQL_TEST_PASSWORD'] ?? '', 'unix_socket' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'prefix_indexes' => true, 'strict' => true, 'engine' => 'InnoDB', 'options' => []];
    }

    public static function configure(Application $app): void
    {
        if (! $app->environment('testing') || $app->configurationIsCached()) {
            throw new RuntimeException('Concurrency tests require APP_ENV=testing and no cached application configuration.');
        }
        $connection = self::settings(self::environment());
        config(['database.default' => 'mysql', 'database.connections.mysql' => $connection]);
        DB::purge('mysql');
        if (DB::selectOne('SELECT DATABASE() AS name')->name !== $connection['database']) {
            throw new RuntimeException('The connected database does not match the disposable test schema.');
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = 60');
    }

    public static function reset(): void
    {
        $expected = self::settings(self::environment());
        if (DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getConfig('host') !== $expected['host']
            || DB::getDatabaseName() !== $expected['database']
            || DB::selectOne('SELECT DATABASE() AS name')->name !== $expected['database']) {
            throw new RuntimeException('Refusing reset because the active connection is not the configured disposable schema.');
        }
        // Refuse to claim a pre-existing database just because its name looks like a test database.
        $tables = Schema::getTableListing($expected['database']);
        $views = Schema::getViews($expected['database']);
        if (($tables !== [] || $views !== []) && (! Schema::hasTable(self::MARKER)
            || DB::table(self::MARKER)->value('database_name') !== DB::getDatabaseName())) {
            throw new RuntimeException('Refusing to reset a nonempty, unmarked database. Create a new empty disposable schema.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        Schema::create(self::MARKER, fn ($table) => $table->string('database_name')->primary());
        DB::table(self::MARKER)->insert(['database_name' => DB::getDatabaseName()]);
    }
}
