<?php

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
});

it('registers an owner and provisions one isolated store with the free subscription', function () {
    $payload = $this->ownerRegistration();
    $response = $this->postJson('/register', $payload)
        ->assertCreated()
        ->assertJsonPath('data.name', $payload['name'])
        ->assertJsonPath('data.email', $payload['email'])
        ->assertJsonPath('data.platform_role', 'user')
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.role', 'owner')
        ->assertJsonPath('data.accounts.0.status', 'active')
        ->assertJsonPath('data.accounts.0.subscription.status', 'active')
        ->assertJsonPath('data.accounts.0.subscription.plan.code', 'free')
        ->assertJsonCount(1, 'data.accounts.0.stores')
        ->assertJsonPath('data.accounts.0.stores.0.name', $payload['store_name'])
        ->assertJsonPath('data.accounts.0.stores.0.status', 'active')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('token');

    $user = User::findOrFail($response->json('data.id'));
    $accountId = $response->json('data.accounts.0.id');
    expect(Hash::check($payload['password'], $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user, 'web');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('accounts', 1);
    $this->assertDatabaseCount('account_memberships', 1);
    $this->assertDatabaseCount('stores', 1);
    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseHas('account_memberships', ['account_id' => $accountId, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
    $this->assertDatabaseHas('stores', ['account_id' => $accountId, 'name' => $payload['store_name']]);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('rejects privileged and tenant identifiers supplied during public registration', function (array $injection) {
    $this->postJson('/register', $this->ownerRegistration($injection))->assertUnprocessable();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('accounts', 0);
    $this->assertGuest('web');
})->with([
    'platform admin role' => [['platform_role' => 'super_admin']],
    'owner role override' => [['role' => 'super_admin']],
    'existing account' => [['account_id' => 999]],
    'existing store' => [['store_id' => 999]],
    'membership payload' => [['memberships' => [['account_id' => 999, 'role' => 'owner']]]],
]);

it('validates registration before creating business records', function (array $invalid, array $errors) {
    $this->postJson('/register', $this->ownerRegistration($invalid))
        ->assertUnprocessable()->assertJsonValidationErrors($errors);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('accounts', 0);
    $this->assertDatabaseCount('stores', 0);
})->with([
    'missing owner name' => [['name' => ''], ['name']],
    'missing store name' => [['store_name' => ''], ['store_name']],
    'invalid email' => [['email' => 'not-an-email'], ['email']],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
    'password confirmation mismatch' => [['password_confirmation' => 'different-password'], ['password']],
]);

it('rejects duplicate email without provisioning a second account', function () {
    $this->createOwner(['email' => 'existing@example.test']);
    $this->postJson('/register', $this->ownerRegistration(['email' => 'existing@example.test']))
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('accounts', 1);
    $this->assertDatabaseCount('stores', 1);
});

it('rolls back all owner provisioning if store creation fails', function () {
    $event = 'eloquent.creating: '.Store::class;
    Event::listen($event, function () {
        throw new RuntimeException('Simulated store provisioning failure.');
    });

    $this->withoutExceptionHandling();
    try {
        $this->postJson('/register', $this->ownerRegistration());
        $this->fail('Expected the simulated provisioning failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated store provisioning failure.');
    } finally {
        Event::forget($event);
    }

    expect(User::count())->toBe(0);
    expect(Account::count())->toBe(0);
    expect(AccountMembership::count())->toBe(0);
    expect(Store::count())->toBe(0);
    expect(Subscription::count())->toBe(0);
    $this->assertGuest('web');
});
