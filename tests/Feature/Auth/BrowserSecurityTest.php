<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\AuthTestHelpers;
use Tests\Support\EnforceCsrfInTests;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
});

it('allows credentialed preflights from exactly the two frontend origins on auth and api paths', function (string $path, string $origin) {
    $this->call('OPTIONS', $path, [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ])->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', $origin)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
})->with([
    ['/register', 'http://localhost:3000'],
    ['/login', 'http://localhost:3000'],
    ['/logout', 'http://localhost:3000'],
    ['/forgot-password', 'http://localhost:3000'],
    ['/reset-password', 'http://localhost:3000'],
    ['/sanctum/csrf-cookie', 'http://localhost:3000'],
    ['/api/v1/me', 'http://localhost:3000'],
    ['/admin/login', 'http://localhost:3001'],
    ['/sanctum/csrf-cookie', 'http://localhost:3001'],
    ['/api/v1/admin/me', 'http://localhost:3001'],
]);

it('does not allow credentialed CORS for wildcard lookalike or arbitrary origins', function (string $origin) {
    $this->call('OPTIONS', '/login', [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ])->assertHeaderMissing('Access-Control-Allow-Origin');

    expect(config('cors.supports_credentials'))->toBeTrue();
    expect(config('cors.allowed_origins'))->not->toContain('*');
    expect(config('cors.allowed_origins_patterns'))->toBe([]);
})->with(['https://attacker.example', 'http://localhost:3000.attacker.example', 'http://localhost:3002']);

it('enforces CSRF on public auth writes even when running the test suite', function (string $path) {
    // Laravel normally skips CSRF in tests. This binding disables only that test bypass.
    $this->app->bind(ValidateCsrfToken::class, EnforceCsrfInTests::class);
    $this->postJson($path, $this->ownerRegistration())->assertStatus(419);
    $this->assertDatabaseCount('users', 0);
})->with(['/register', '/login', '/admin/login', '/forgot-password', '/reset-password']);

it('rejects a mismatched csrf token without provisioning an account', function () {
    $this->app->bind(ValidateCsrfToken::class, EnforceCsrfInTests::class);
    $this->withSession(['_token' => 'the-correct-session-token'])
        ->withHeader('X-CSRF-TOKEN', 'incorrect-token')
        ->postJson('/register', $this->ownerRegistration())->assertStatus(419);
    $this->assertDatabaseCount('accounts', 0);
});

it('accepts the real encrypted xsrf cookie flow and issues a protected session cookie', function () {
    config(['session.driver' => 'database']);
    $this->app['session']->forgetDrivers();
    $this->app->bind(ValidateCsrfToken::class, EnforceCsrfInTests::class);
    $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $xsrf = $response->getCookie('XSRF-TOKEN', false);
    $session = $response->getCookie(config('session.cookie'), false);
    expect($xsrf)->not->toBeNull();
    expect($session)->not->toBeNull();
    expect($xsrf->isHttpOnly())->toBeFalse();
    expect($session->isHttpOnly())->toBeTrue();
    expect($session->getSameSite())->toBe('lax');

    $registered = $this->withUnencryptedCookie(config('session.cookie'), $session->getValue())
        ->withHeader('X-XSRF-TOKEN', $xsrf->getValue())
        ->postJson('/register', $this->ownerRegistration())->assertCreated();
    $authenticatedCookie = $registered->getCookie(config('session.cookie'), false);
    expect($authenticatedCookie)->not->toBeNull();
    $this->resetBrowserGuards();
    $this->withUnencryptedCookie(config('session.cookie'), $authenticatedCookie->getValue())
        ->getJson('/api/v1/me')->assertOk();
});
