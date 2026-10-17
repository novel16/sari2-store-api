<?php

use App\Models\Plan;
use Tests\Support\CatalogTestHelpers;

uses(CatalogTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->base = '/api/v1/stores/'.$this->store->id.'/products';
    Plan::where('code', 'free')->update(['active_product_limit' => 2]);
    $this->actingAs($this->owner, 'web');
});

it('enforces the active product cap on both creation and restoration', function () {
    $first = $this->createProduct($this->store);
    $this->createProduct($this->store);
    $this->postJson($this->base, $this->productData())->assertConflict();
    $archived = $this->createProduct($this->store, ['status' => 'archived']);
    $this->patchJson($this->base.'/'.$archived->id, ['status' => 'active'])->assertConflict();
    $this->deleteJson($this->base.'/'.$first->id)->assertOk();
    $this->patchJson($this->base.'/'.$archived->id, ['status' => 'active'])->assertOk();
    $this->getJson($this->base)->assertOk()->assertJsonPath('meta.quota.active_products', 2)->assertJsonPath('meta.quota.active_product_limit', 2);
    $this->assertDatabaseCount('inventory_movements', 3);
});

it('allows editing archiving and viewing existing products after a downgrade', function () {
    $first = $this->createProduct($this->store);
    $this->createProduct($this->store);
    Plan::where('code', 'free')->update(['active_product_limit' => 1]);
    $this->getJson($this->base)->assertOk()->assertJsonCount(2, 'data');
    $this->patchJson($this->base.'/'.$first->id, ['name' => 'Updated', 'status' => 'active'])->assertOk();
    $this->deleteJson($this->base.'/'.$first->id)->assertOk();
    $this->postJson($this->base, $this->productData())->assertConflict();
});

it('counts quotas per store rather than combining branch products', function () {
    $this->createProduct($this->store);
    $this->createProduct($this->store);
    $branch = $this->store->account->stores()->create(['name' => 'Branch', 'slug' => 'branch', 'status' => 'active']);
    $this->createProduct($branch);
    $this->createProduct($branch);
    $this->postJson('/api/v1/stores/'.$branch->id.'/products', $this->productData())->assertConflict();
});

it('falls back to free limits when the latest subscription is expired inactive or future dated', function (array $subscription) {
    $plan = Plan::create(['code' => 'test-unlimited', 'name' => 'Test plan', 'active_product_limit' => null]);
    $this->store->account->subscriptions()->create(array_replace([
        'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => null,
    ], $subscription));
    $this->createProduct($this->store);
    $this->createProduct($this->store);
    $this->postJson($this->base, $this->productData())->assertConflict();
})->with([
    'expired' => [fn () => ['ends_at' => now()->subMinute()]],
    'inactive' => [['status' => 'cancelled']],
    'future' => [fn () => ['starts_at' => now()->addDay()]],
]);

it('honors a valid plan with a null active-product limit', function () {
    Plan::where('code', 'free')->update(['active_product_limit' => null]);
    $this->createProduct($this->store);
    $this->createProduct($this->store);
    $this->createProduct($this->store);
    $this->getJson($this->base)->assertOk()->assertJsonPath('meta.quota.active_product_limit', null);
});
