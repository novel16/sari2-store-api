<?php

use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\Subscriptions\ActivateSubscription;
use App\Services\Admin\Subscriptions\SubscriptionQuote;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Str;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);

it('shows manual periods to the owner and preserves POS and repayment access after expiry above the Free cap', function () {
    $this->seed(PlanSeeder::class);
    Plan::where('code', 'free')->update(['active_product_limit' => 1]);
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $owner = $this->createOwner();
    $store = $this->ownerStore($owner);
    $admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $input = ['plan_id' => Plan::where('code', 'basic')->value('id'), 'months' => 1,
        'settlement_method' => 'complimentary', 'amount_centavos' => 0, 'reason' => 'Launch promotion approved'];
    $quote = app(SubscriptionQuote::class)->create($store->account, $admin, $input);
    app(ActivateSubscription::class)->handle($store->account, $admin, ['quote' => $quote['quote'], 'request_key' => (string) Str::uuid()]);
    $this->actingAs($owner, 'web');
    $base = '/api/v1/stores/'.$store->id;
    $product = $this->createProduct($store);
    $this->createProduct($store, ['name' => 'Second product']);
    $this->getJson($base.'/subscription')->assertOk()->assertJsonPath('data.current.plan.code', 'basic')
        ->assertJsonPath('data.current.saved_terms', true)->assertJsonPath('data.manual_activation_available', true)
        ->assertJsonPath('data.renewal.auto_renew', false)->assertJsonPath('data.usage.active_product_limit', 500)
        ->assertJsonMissingPath('data.payment_reference')->assertJsonMissingPath('data.reason');
    $this->travelTo(CarbonImmutable::parse($quote['proposal']['ends_at']));
    $this->getJson($base.'/subscription')->assertOk()->assertJsonPath('data.current.plan.code', 'free')
        ->assertJsonPath('data.usage.active_products', 2)->assertJsonPath('data.usage.over_limit', true)
        ->assertJsonPath('data.renewal.last_paid_ended_at', CarbonImmutable::parse($quote['proposal']['ends_at'])->toJSON());
    $this->postJson($base.'/products', $this->productData())->assertConflict();
    $this->patchJson($base.'/products/'.$product->id, ['name' => 'Still available'])->assertOk();
    $this->postJson($base.'/sales', $this->checkoutData($product))->assertCreated();
    $customerId = $this->postJson($base.'/customers', ['name' => 'Aling Linda', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $credit = $this->checkoutData($product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $customerId];
    $this->postJson($base.'/sales', $credit)->assertCreated();
    $this->postJson($base.'/customers/'.$customerId.'/payments', ['request_key' => (string) Str::uuid(),
        'amount_centavos' => 1000, 'method' => 'cash', 'expected_balance_centavos' => '2900'])->assertCreated();
    $this->assertDatabaseCount('sales', 2);
    $this->assertDatabaseCount('subscription_activations', 1);
});
