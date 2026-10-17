<?php

use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\SavePlan;
use App\Services\Auth\RegisterStoreOwner;
use Database\Seeders\PlanSeeder;
use Tests\Support\CatalogTestHelpers;

uses(CatalogTestHelpers::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
});

it('returns actual plan prices and usage with planned features clearly separated', function () {
    $this->createProduct($this->store);
    $this->createProduct($this->store, ['status' => 'archived']);
    Plan::create(['code' => 'private', 'name' => 'Private draft', 'catalog_status' => 'draft']);
    $this->getJson($this->base.'/subscription')->assertOk()->assertJsonPath('data.current.plan.code', 'free')
        ->assertJsonPath('data.usage.active_products', 1)->assertJsonPath('data.usage.active_product_limit', 100)
        ->assertJsonCount(4, 'data.plans')->assertJsonPath('data.plans.1.monthly_price_centavos', 4900)
        ->assertJsonPath('data.plans.2.catalog_status', 'coming_soon')->assertJsonPath('data.billing_available', false)
        ->assertJsonMissing(['code' => 'private']);
    $this->postJson($this->base.'/subscription', ['plan_id' => Plan::where('code', 'basic')->value('id')])->assertStatus(405);
});

it('preserves agreed paid terms through catalog edits and retirement', function () {
    $basic = Plan::where('code', 'basic')->firstOrFail();
    $subscription = $this->store->account->subscriptions()->create(['plan_id' => $basic->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
    app(SavePlan::class)->handle(['monthly_price_centavos' => 9900, 'active_product_limit' => 200, 'catalog_status' => 'retired', 'expected_version' => 1], $basic);
    expect($subscription->fresh()->plan_version_id)->not->toBeNull();
    $this->getJson($this->base.'/subscription')->assertOk()->assertJsonPath('data.current.plan.code', 'basic')
        ->assertJsonPath('data.current.plan.monthly_price_centavos', 4900)->assertJsonPath('data.usage.active_product_limit', 500)
        ->assertJsonPath('data.current.saved_terms', true)->assertJsonCount(3, 'data.plans');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.accounts.0.subscription.plan.active_product_limit', 500);
});

it('keeps the current paid period when a later subscription is scheduled in the future', function () {
    $basic = Plan::where('code', 'basic')->firstOrFail();
    $this->store->account->subscriptions()->create(['plan_id' => $basic->id, 'status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => now()->addMonth()]);
    $this->store->account->subscriptions()->create(['plan_id' => Plan::where('code', 'plus')->value('id'), 'status' => 'active', 'starts_at' => now()->addMonth()]);
    $this->getJson($this->base.'/subscription')->assertOk()->assertJsonPath('data.current.plan.code', 'basic');
});

it('falls back after expiry and preserves existing products while blocking additions over the cap', function () {
    Plan::where('code', 'free')->update(['active_product_limit' => 1]);
    $subscription = $this->store->account->subscriptions()->create(['plan_id' => Plan::where('code', 'basic')->value('id'), 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $first = $this->createProduct($this->store);
    $this->createProduct($this->store);
    $subscription->update(['ends_at' => now()->subMinute()]);
    $this->getJson($this->base.'/subscription')->assertOk()->assertJsonPath('data.usage.over_limit', true)->assertJsonPath('data.current.plan.code', 'free');
    $this->getJson($this->base.'/products')->assertOk()->assertJsonCount(2, 'data');
    $this->patchJson($this->base.'/products/'.$first->id, ['name' => 'Still editable'])->assertOk();
    $this->postJson($this->base.'/products', $this->productData())->assertConflict();
});

it('counts pooled Pro limits across branches without counting archived products', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    $pro->update(['active_product_limit' => 2]);
    $this->store->account->subscriptions()->create(['plan_id' => $pro->id, 'status' => 'active', 'starts_at' => now()]);
    $branch = $this->store->account->stores()->create(['name' => 'Branch', 'slug' => 'branch', 'status' => 'active']);
    $first = $this->createProduct($this->store);
    $this->createProduct($branch);
    $this->postJson($this->base.'/products', $this->productData())->assertConflict();
    $this->postJson('/api/v1/stores/'.$branch->id.'/products', $this->productData())->assertConflict();
    $this->getJson($this->base.'/subscription')->assertOk()->assertJsonPath('data.usage.quota_scope', 'account')->assertJsonPath('data.usage.active_products', 2);
    $this->deleteJson($this->base.'/products/'.$first->id)->assertOk();
    $this->createProduct($branch);
});

it('keeps another owners subscription private and the admin independent', function () {
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->actingAs($other, 'web')->getJson($this->base.'/subscription')->assertNotFound();
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->actingAs($admin, 'web')->getJson($this->base.'/subscription')->assertNotFound();
});
