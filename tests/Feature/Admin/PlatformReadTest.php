<?php

use App\Models\Account;
use App\Models\Store;
use App\Models\User;
use App\Services\Auth\RegisterStoreOwner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

dataset('admin read endpoints', [
    '/api/v1/admin/me', '/api/v1/admin/dashboard', '/api/v1/admin/accounts',
    '/api/v1/admin/accounts/1', '/api/v1/admin/stores', '/api/v1/admin/stores/1',
]);

it('requires authentication before exposing admin metadata', function (string $path) {
    $this->getJson($path)->assertUnauthorized();
})->with('admin read endpoints');

it('rejects store owners on every admin endpoint', function (string $path) {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->actingAs($owner, 'web')->getJson($path)->assertForbidden();
})->with('admin read endpoints');

it('rejects suspended administrators on every admin endpoint', function (string $path) {
    app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->admin->forceFill(['status' => 'suspended'])->save();
    $this->actingAs($this->admin, 'web')->getJson($path)->assertForbidden();
})->with('admin read endpoints');

it('does not require an administrator to have an owner membership', function () {
    $this->actingAs($this->admin, 'web');
    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.summary.accounts', ['total' => 0, 'active' => 0, 'suspended' => 0])
        ->assertJsonPath('data.summary.users.total', 1)
        ->assertJsonPath('data.summary.administrators.active', 1)
        ->assertJsonPath('data.recent_accounts', [])
        ->assertJsonPath('data.recent_stores', [])
        ->assertJsonCount(30, 'data.registrations');
    $this->getJson('/api/v1/admin/accounts')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/admin/stores')->assertOk()->assertJsonPath('meta.total', 0);
});

it('counts actual status records and places registrations in Philippine calendar days', function () {
    $now = Carbon::parse('2026-10-16 03:30:00', 'UTC');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);
    $first = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $second = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $third = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->ownerStore($first)->account->forceFill(['created_at' => '2026-10-15 16:00:00'])->save();
    $this->ownerStore($second)->account->forceFill(['created_at' => '2026-09-16 15:59:59', 'status' => 'suspended'])->save();
    $this->ownerStore($third)->account->forceFill(['created_at' => '2026-10-15 15:59:59'])->save();
    $this->ownerStore($second)->update(['status' => 'suspended']);
    $second->forceFill(['status' => 'suspended'])->save();
    User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'suspended']);

    $response = $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.summary.accounts', ['total' => 3, 'active' => 2, 'suspended' => 1])
        ->assertJsonPath('data.summary.stores', ['total' => 3, 'active' => 2, 'suspended' => 1])
        ->assertJsonPath('data.summary.users', ['total' => 5, 'active' => 3, 'suspended' => 2])
        ->assertJsonPath('data.summary.administrators', ['total' => 2, 'active' => 1, 'suspended' => 1])
        ->assertJsonPath('data.date_from', '2026-09-17')
        ->assertJsonPath('data.date_to', '2026-10-16')
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.registrations.28.accounts', 1)
        ->assertJsonPath('data.registrations.29.accounts', 1);
    expect(array_sum(array_column($response->json('data.registrations'), 'accounts')))->toBe(2);
});

it('searches accounts by account name and owner identity with stable pagination', function () {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration(['name' => 'Maria Reyes', 'store_name' => 'Reyes Corner', 'email' => 'maria@example.test']));
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration(['name' => 'Juan Santos', 'store_name' => 'Santos Mart']));
    $account = $this->ownerStore($owner)->account;
    $otherAccount = $this->ownerStore($other)->account;
    $otherAccount->update(['status' => 'suspended']);
    $this->actingAs($this->admin, 'web');
    foreach (['Reyes Corner', 'Maria Reyes', 'maria@example.test', '  Reyes  '] as $term) {
        $this->getJson('/api/v1/admin/accounts?'.http_build_query(['q' => $term]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $account->id);
    }
    $this->getJson('/api/v1/admin/accounts?status=suspended')->assertOk()->assertJsonPath('data.0.id', $otherAccount->id);
    $this->getJson('/api/v1/admin/accounts?status=active&q=Santos')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/accounts?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $otherAccount->id);
    $this->getJson('/api/v1/admin/accounts?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $account->id)->assertJsonPath('meta.current_page', 2);
    $this->getJson('/api/v1/admin/accounts?per_page=1&page=3')->assertOk()->assertJsonCount(0, 'data');
});

it('combines store search, status and parent account filters without leaking other matches', function () {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration(['name' => 'Maria Reyes', 'email' => 'maria@example.test', 'store_name' => 'Reyes Corner']));
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration(['name' => 'Juan Santos', 'store_name' => 'Other Store']));
    $store = $this->ownerStore($owner);
    $branch = Store::create(['account_id' => $store->account_id, 'name' => 'East Branch', 'slug' => 'east', 'status' => 'suspended']);
    $this->actingAs($this->admin, 'web');
    foreach (['Reyes Corner', 'Maria Reyes', 'maria@example.test'] as $term) {
        $this->getJson('/api/v1/admin/stores?'.http_build_query(['q' => $term]))->assertOk()->assertJsonCount(2, 'data');
    }
    $this->getJson('/api/v1/admin/stores?'.http_build_query(['account_id' => $store->account_id, 'status' => 'suspended']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $branch->id);
    $this->getJson('/api/v1/admin/stores?'.http_build_query(['account_id' => $this->ownerStore($other)->account_id, 'q' => 'Maria']))
        ->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/stores?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
});

it('exposes only allowlisted account and store metadata', function () {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $store = $this->ownerStore($owner);
    $this->actingAs($this->admin, 'web');
    $account = $this->getJson('/api/v1/admin/accounts/'.$store->account_id)->assertOk()
        ->assertJsonPath('data.members_count', 1)->assertJsonPath('data.stores_count', 1)->json('data');
    expect(array_keys($account))->toEqualCanonicalizing(['id', 'name', 'status', 'owner', 'stores_count', 'members_count', 'created_at', 'updated_at']);
    expect(array_keys($account['owner']))->toEqualCanonicalizing(['id', 'name', 'email', 'status']);
    $profile = $this->getJson('/api/v1/admin/stores/'.$store->id)->assertOk()->json('data');
    expect(array_keys($profile))->toEqualCanonicalizing(['id', 'account_id', 'name', 'slug', 'status', 'phone', 'address', 'account', 'created_at', 'updated_at']);
    expect(array_keys($profile['account']))->toEqualCanonicalizing(['id', 'name', 'status', 'owner']);
    expect(array_keys($profile['account']['owner']))->toEqualCanonicalizing(['id', 'name', 'email', 'status']);
    foreach (['sales', 'customers', 'products', 'inventory', 'reports/sales'] as $path) {
        $this->getJson('/api/v1/stores/'.$store->id.'/'.$path)->assertNotFound();
    }
});

it('handles missing records and revoked admin roles', function () {
    $this->actingAs($this->admin, 'web');
    foreach (['accounts/999999', 'stores/999999', 'stores/invalid', 'accounts/-1'] as $path) {
        $this->getJson('/api/v1/admin/'.$path)->assertNotFound();
    }
    $this->admin->forceFill(['platform_role' => 'user'])->save();
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('bounds directory query input', function (array $query, string $field) {
    $this->actingAs($this->admin, 'web');
    foreach (['accounts', 'stores'] as $path) {
        $this->getJson('/api/v1/admin/'.$path.'?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
})->with([
    [['q' => ['unexpected']], 'q'], [['q' => str_repeat('x', 121)], 'q'],
    [['status' => 'deleted'], 'status'], [['per_page' => 101], 'per_page'],
    [['per_page' => 0], 'per_page'], [['page' => 0], 'page'],
    [['page' => 1000001], 'page'], [['account_id' => -1], 'account_id'],
]);

it('keeps this admin batch read only', function () {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $store = $this->ownerStore($owner);
    $this->actingAs($this->admin, 'web');
    $this->postJson('/api/v1/admin/accounts', ['name' => 'Changed'])->assertStatus(405);
    $this->patchJson('/api/v1/admin/accounts/'.$store->account_id, ['status' => 'suspended'])->assertStatus(405);
    $this->deleteJson('/api/v1/admin/stores/'.$store->id)->assertStatus(405);
    expect($store->refresh()->status)->toBe('active');
    expect(Account::findOrFail($store->account_id)->status)->toBe('active');
});
