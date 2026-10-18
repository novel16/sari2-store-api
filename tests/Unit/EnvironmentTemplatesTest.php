<?php

use Dotenv\Dotenv;
use Illuminate\Support\Env;

function apiEnvironmentTemplate(string $file): array
{
    return Dotenv::parse(file_get_contents(dirname(__DIR__, 2).'/'.$file));
}

it('provides complete environment templates without application keys or database credentials', function (string $file, string $environment) {
    $values = apiEnvironmentTemplate($file);
    foreach (['APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'FRONTEND_URL', 'ADMIN_URL', 'CORS_ALLOWED_ORIGINS',
        'SANCTUM_STATEFUL_DOMAINS', 'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'DB_USERNAME', 'DB_PASSWORD', 'SESSION_DRIVER', 'SESSION_DOMAIN', 'SESSION_SECURE_COOKIE',
        'SESSION_SAME_SITE', 'QUEUE_CONNECTION', 'CACHE_STORE', 'MAIL_MAILER', 'FREE_PRODUCT_LIMIT'] as $name) {
        expect($values)->toHaveKey($name);
    }
    expect($values['APP_ENV'])->toBe($environment);
    foreach (['APP_KEY', 'DB_URL', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
        expect($values[$name])->toBe('');
    }
})->with([
    ['.env.example', 'local'], ['.env.development.example', 'development'], ['.env.production.example', 'production'],
]);

it('keeps remote templates private by default with matching HTTPS SPA cookie domains', function (string $file) {
    $values = apiEnvironmentTemplate($file);
    expect($values['APP_DEBUG'])->toBe('false');
    expect($values['SESSION_SECURE_COOKIE'])->toBe('true');
    expect($values['SESSION_SAME_SITE'])->toBe('lax');
    expect($values['LOG_STACK'])->toBe('daily');
    $origins = explode(',', $values['CORS_ALLOWED_ORIGINS']);
    expect($origins)->toContain($values['FRONTEND_URL']);
    expect($origins)->toContain($values['ADMIN_URL']);
    expect($values['CORS_ALLOWED_ORIGINS'])->not->toContain('*');
    $hosts = [];
    foreach ([$values['APP_URL'], ...$origins] as $origin) {
        expect(parse_url($origin, PHP_URL_SCHEME))->toBe('https');
        $host = parse_url($origin, PHP_URL_HOST);
        expect(str_ends_with($host, $values['SESSION_DOMAIN']))->toBeTrue();
        $hosts[] = $host;
    }
    expect(explode(',', $values['SANCTUM_STATEFUL_DOMAINS']))->toBe(array_slice($hosts, 1));
})->with(['.env.development.example', '.env.production.example']);

it('separates remote session and cache names and leaves production mail explicit', function () {
    $development = apiEnvironmentTemplate('.env.development.example');
    $production = apiEnvironmentTemplate('.env.production.example');
    expect($development['SESSION_COOKIE'])->not->toBe($production['SESSION_COOKIE']);
    expect($development['CACHE_PREFIX'])->not->toBe($production['CACHE_PREFIX']);
    expect($development['MAIL_MAILER'])->toBe('log');
    expect($production['MAIL_MAILER'])->toBe('smtp');
    expect($production['MAIL_HOST'])->toBe('');
    expect($production['LOG_LEVEL'])->toBe('warning');
});

it('forces testing onto its own in-memory database and service backends', function (string $file) {
    $xml = simplexml_load_file(dirname(__DIR__, 2).'/'.$file);
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
        'DB_URL' => '', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $name => $expected) {
        $entry = $xml->xpath('//php/env[@name="'.$name.'"]')[0];
        expect((string) $entry['value'])->toBe($expected);
        expect((string) $entry['force'])->toBe('true');
        $server = $xml->xpath('//php/server[@name="'.$name.'"]')[0];
        expect((string) $server['value'])->toBe($expected);
    }
})->with(['phpunit.xml', 'phpunit.mysql.xml']);

it('resolves safe testing values even when the parent process supplies server variables', function () {
    foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
        'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $name => $value) {
        expect(Env::get($name))->toBe($value);
    }
});
