<?php

use App\Models\User;
use App\Services\Auth\RegisterStoreOwner;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->actingAs(User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']), 'web');
});

it('keeps admin directory reads constant for different owners and larger pages', function (string $endpoint) {
    for ($index = 0; $index < 12; $index++) {
        app(RegisterStoreOwner::class)->handle($this->ownerRegistration(['name' => 'Owner '.$index, 'store_name' => 'Store '.$index]));
    }
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson('/api/v1/admin/'.$endpoint.'?per_page=1')->assertOk()->assertJsonCount(1, 'data'),
        fn () => $this->getJson('/api/v1/admin/'.$endpoint.'?per_page=12')->assertOk()->assertJsonCount(12, 'data'),
    );
})->with(['accounts', 'stores']);

it('batches owners and accounts across both recent dashboard lists', function () {
    app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $small = $this->readQueries(fn () => $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonCount(1, 'data.recent_accounts'));
    for ($index = 0; $index < 11; $index++) {
        app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    }
    $large = $this->readQueries(fn () => $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonCount(5, 'data.recent_accounts')->assertJsonCount(5, 'data.recent_stores')->assertJsonPath('data.summary.accounts.total', 12));
    expect(count($large))->toBe(count($small));
});
