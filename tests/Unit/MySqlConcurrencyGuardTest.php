<?php

use Tests\Support\MySqlConcurrencyDatabase;

it('refuses mysql reset settings that could target an application database', function (array $changes) {
    $environment = array_replace([
        'MYSQL_TEST_HOST' => '127.0.0.1', 'MYSQL_TEST_USERNAME' => 'test_runner',
        'MYSQL_TEST_DATABASE' => 'sarisari_concurrency_test_ci',
        'MYSQL_TEST_ALLOW_RESET' => 'sarisari_concurrency_test_ci',
    ], $changes);

    expect(fn () => MySqlConcurrencyDatabase::settings($environment))->toThrow(RuntimeException::class);
})->with([
    'application schema' => [['MYSQL_TEST_DATABASE' => 'sarisari_store_pos']],
    'missing consent' => [['MYSQL_TEST_ALLOW_RESET' => '']],
    'different consent' => [['MYSQL_TEST_ALLOW_RESET' => 'sarisari_concurrency_test_other']],
    'remote server' => [['MYSQL_TEST_HOST' => 'production.example.test']],
    'implicit credentials' => [['MYSQL_TEST_USERNAME' => '']],
    'invalid port' => [['MYSQL_TEST_PORT' => '3306;DROP DATABASE app']],
]);

it('uses only explicit mysql test settings', function () {
    $settings = MySqlConcurrencyDatabase::settings([
        'MYSQL_TEST_HOST' => '127.0.0.1', 'MYSQL_TEST_USERNAME' => 'test_runner',
        'MYSQL_TEST_DATABASE' => 'sarisari_concurrency_test_ci',
        'MYSQL_TEST_ALLOW_RESET' => 'sarisari_concurrency_test_ci',
    ]);

    expect($settings['database'])->toBe('sarisari_concurrency_test_ci')
        ->and($settings['password'])->toBe('')->and($settings['url'])->toBeNull()
        ->and($settings['unix_socket'])->toBe('');
});
