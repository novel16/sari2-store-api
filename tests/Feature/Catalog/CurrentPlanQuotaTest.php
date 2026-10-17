<?php

use App\Models\Plan;
use App\Services\Catalog\ProductQuota;
use Tests\Support\CatalogTestHelpers;

uses(CatalogTestHelpers::class);

it('uses the current active subscriptions plan allowance', function () {
    $owner = $this->createOwner();
    $store = $this->ownerStore($owner);
    $plan = Plan::create(['code' => 'test-private', 'name' => 'Test plan', 'active_product_limit' => 1, 'is_active' => true]);
    $subscription = $store->account->currentSubscription;
    $start = $subscription->starts_at->toIso8601String();
    $subscription->update(['plan_id' => $plan->id]);
    expect($subscription->fresh()->starts_at->toIso8601String())->toBe($start);
    expect(app(ProductQuota::class)->forStore($store))->toMatchArray(['plan_name' => 'Test plan', 'active_product_limit' => 1]);
});
