<?php

use App\Models\Billing\SubscriptionOrder;
use App\Models\Plan;
use App\Services\Auth\RegisterStoreOwner;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    config(['paymongo.enabled' => true, 'paymongo.mode' => 'test', 'paymongo.secret_key' => 'sk_test_fixtureonly',
        'paymongo.webhook_secret' => 'whsec_fixture_only']);
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->store = $this->ownerStore($this->owner);
    $this->base = '/api/v1/stores/'.$this->store->id.'/subscription/orders';
    $basic = Plan::where('code', 'basic')->firstOrFail();
    $this->input = ['request_key' => (string) Str::uuid(), 'plan_id' => $basic->id,
        'expected_plan_version' => $basic->version, 'months' => 1];
    $this->providerStatus = 'expired';
    $this->providerUnavailable = false;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $creating = str_ends_with($request->url(), '/v2/checkout_sessions');
        if (! $creating && $this->providerUnavailable) {
            throw new ConnectionException('Simulated provider outage');
        }
        $order = SubscriptionOrder::firstOrFail();

        return Http::response(['data' => ['id' => 'cs_expiryfixture', 'type' => 'checkout_session',
            'attributes' => ['livemode' => false, 'reference_number' => $order->uuid,
                'metadata' => ['order_id' => $order->uuid], 'payments' => [],
                'checkout_url' => 'https://checkout.paymongo.com/cs_expiryfixture',
                'status' => $creating ? 'active' : $this->providerStatus]]], 200);
    });
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->input)->assertCreated();
    $this->order = SubscriptionOrder::firstOrFail();
});

it('does not release a payable checkout merely because the local clock has passed its deadline', function () {
    $this->travel(31)->minutes();
    $this->getJson($this->base.'/'.$this->order->uuid)->assertOk()->assertJsonPath('data.payment_status', 'pending');
    $this->postJson($this->base, [...$this->input, 'request_key' => (string) Str::uuid()])->assertConflict();
    expect($this->order->fresh()->active_account_id)->toBe($this->store->account_id);
    Http::assertSentCount(1);
});

it('releases the unresolved slot only after the provider confirms expiration', function () {
    $this->postJson($this->base.'/'.$this->order->uuid.'/expire')->assertOk()->assertJsonPath('data.payment_status', 'expired')
        ->assertJsonPath('data.can_resume', false)->assertJsonPath('data.checkout_url', null);
    expect($this->order->fresh()->active_account_id)->toBeNull()->and($this->order->fresh()->paid_at)->toBeNull();
    Http::assertSentCount(2);
    $this->assertDatabaseCount('subscriptions', 1);
});

it('retains the slot when provider cancellation is ambiguous and never marks the order paid from that response', function () {
    $this->providerStatus = 'completed';
    $this->postJson($this->base.'/'.$this->order->uuid.'/expire')->assertConflict();
    expect($this->order->fresh()->payment_status)->toBe('pending')
        ->and($this->order->fresh()->issue_code)->toBe('awaiting_payment_webhook')
        ->and($this->order->fresh()->active_account_id)->toBe($this->store->account_id);
    $this->assertDatabaseCount('subscriptions', 1);
});

it('keeps the original checkout available after a provider outage', function () {
    $this->providerUnavailable = true;
    $this->postJson($this->base.'/'.$this->order->uuid.'/expire')->assertStatus(503)->assertJsonPath('code', 'billing_provider_unavailable');
    expect($this->order->fresh()->payment_status)->toBe('pending')
        ->and($this->order->fresh()->active_account_id)->toBe($this->store->account_id);
    $this->getJson($this->base.'/'.$this->order->uuid)->assertOk()->assertJsonPath('data.can_resume', true);
});

it('never reopens or downgrades a verified paid order when cancellation is retried', function () {
    $this->order->update(['payment_status' => 'paid', 'payment_id' => 'pay_expiryfixture', 'paid_at' => now()]);
    $this->postJson($this->base.'/'.$this->order->uuid.'/expire')->assertOk()->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.checkout_url', null)->assertJsonPath('data.can_cancel', false);
    Http::assertSentCount(1);
    $this->assertDatabaseCount('subscriptions', 1);
});
