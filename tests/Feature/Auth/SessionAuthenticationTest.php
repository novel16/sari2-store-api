<?php

use App\Models\User;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
});

it('requires authentication for both current-user endpoints', function (string $endpoint) {
    $this->getJson($endpoint)->assertUnauthorized();
})->with(['/api/v1/me', '/api/v1/admin/me']);

it('logs an owner in through the client and logs out the session', function () {
    $user = $this->createOwner();
    $this->postJson('/login', ['email' => $user->email, 'password' => 'secure-password-123'])
        ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('token');
    $this->assertAuthenticatedAs($user, 'web');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    $this->postJson('/logout')->assertOk();
    $this->assertGuest('web');
    $this->resetBrowserGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('restores the owner session from the browser cookie without a cached auth guard', function () {
    config(['session.driver' => 'database']);
    $this->app['session']->forgetDrivers();
    $response = $this->postJson('/register', $this->ownerRegistration())->assertCreated();
    $userId = $response->json('data.id');
    $cookieName = config('session.cookie');
    $cookie = $response->getCookie($cookieName, false);
    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();

    $this->resetBrowserGuards();
    $this->withUnencryptedCookie($cookieName, $cookie->getValue())
        ->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $userId);

    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('regenerates the guest session identifier on successful login', function () {
    $user = $this->createOwner();
    $this->withSession(['guest_marker' => 'retained']);
    $guestId = $this->app['session']->getId();
    $this->postJson('/login', ['email' => $user->email, 'password' => 'secure-password-123'])->assertOk();

    expect($this->app['session']->getId())->not->toBe($guestId);
    $this->assertAuthenticatedAs($user, 'web');
});

it('uses the same generic credential error for unknown email and incorrect password', function () {
    $user = $this->createOwner();
    $unknown = $this->postJson('/login', ['email' => 'unknown@example.test', 'password' => 'wrong-password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $wrong = $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);

    expect($wrong->json('errors.email'))->toBe($unknown->json('errors.email'));
    $this->assertGuest('web');
});

it('does not authenticate a suspended user', function () {
    $user = $this->createOwner();
    $user->forceFill(['status' => 'suspended'])->save();
    $this->postJson('/login', ['email' => $user->email, 'password' => 'secure-password-123'])->assertForbidden();
    $this->assertGuest('web');
});

it('rechecks user suspension for an already authenticated session', function () {
    $user = $this->createOwner();
    $user->forceFill(['status' => 'suspended'])->save();
    $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/me')->assertForbidden();
});

it('admits only a platform admin through the admin login and endpoint', function () {
    $admin = User::factory()->create(['password' => 'secure-password-123']);
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->postJson('/admin/login', ['email' => $admin->email, 'password' => 'secure-password-123'])
        ->assertOk()->assertJsonPath('data.id', $admin->id)->assertJsonPath('data.platform_role', 'platform_admin');
    $this->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.id', $admin->id);
});

it('rejects a store owner on the admin login and admin API', function () {
    $owner = $this->createOwner();
    $this->postJson('/admin/login', ['email' => $owner->email, 'password' => 'secure-password-123'])->assertForbidden();
    $this->assertGuest('web');
    $this->actingAs($owner, 'web')->getJson('/api/v1/admin/me')->assertForbidden();
});

it('keeps platform admins out of the store-owner login flow', function () {
    $admin = User::factory()->create(['password' => 'secure-password-123']);
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->postJson('/login', ['email' => $admin->email, 'password' => 'secure-password-123'])->assertForbidden();
    $this->assertGuest('web');
});
