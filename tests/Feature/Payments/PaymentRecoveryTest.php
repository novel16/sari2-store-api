<?php

use App\Models\Billing\PayMongoWebhookEvent;
use App\Models\Billing\SubscriptionOrder;
use App\Models\Plan;
use App\Services\Auth\RegisterStoreOwner;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AuthTestHelpers;
use Tests\Support\PayMongoPaymentFixtures;

uses(AuthTestHelpers::class, PayMongoPaymentFixtures::class);

function recoveryCheckoutFixture(string $reference, string $id): array
{
    return ['id' => $id, 'type' => 'checkout_session', 'attributes' => ['livemode' => false,
        'reference_number' => $reference, 'metadata' => ['order_id' => $reference],
        'checkout_url' => 'https://checkout.paymongo.com/cs_recoveryfixture', 'status' => 'active', 'payments' => []]];
}

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    config(['paymongo.enabled' => true, 'paymongo.mode' => 'test', 'paymongo.secret_key' => 'sk_test_fixtureonly',
        'paymongo.webhook_secret' => 'whsec_fixture_only']);
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $store = $this->ownerStore($this->owner);
    $this->base = '/api/v1/stores/'.$store->id.'/subscription/orders';
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $resource = str_ends_with($request->url(), '/v2/checkout_sessions')
            ? recoveryCheckoutFixture($request['data']['attributes']['reference_number'], 'cs_createfixture')
            : $this->retrievedCheckout;

        return Http::response(['data' => $resource], 200);
    });
    $basic = Plan::where('code', 'basic')->firstOrFail();
    $this->actingAs($this->owner, 'web')->postJson($this->base, ['request_key' => (string) Str::uuid(),
        'plan_id' => $basic->id, 'expected_plan_version' => $basic->version, 'months' => 1])->assertCreated();
    $this->order = SubscriptionOrder::firstOrFail();
    $this->order->update(['payment_status' => 'unknown', 'checkout_session_id' => null, 'checkout_url' => null]);
    $this->retrievedCheckout = recoveryCheckoutFixture($this->order->uuid, 'cs_recoveryfixture');
    Queue::fake();
});

it('attaches the existing matching checkout without issuing another create or marking paid', function () {
    $this->artisan('billing:reconcile', ['--order' => $this->order->uuid, '--session' => 'cs_recoveryfixture'])->assertExitCode(0);
    $order = $this->order->fresh();
    expect($order->checkout_session_id)->toBe('cs_recoveryfixture')->and($order->payment_status)->toBe('pending')
        ->and($order->payment_id)->toBeNull()->and($order->paid_at)->toBeNull();
    Http::assertSentCount(2);
    $this->assertDatabaseCount('subscription_orders', 1);
    $this->assertDatabaseCount('subscriptions', 1);
});

it('refuses operator recovery when session identity reference metadata or mode disagrees', function (string $mismatch) {
    if ($mismatch === 'session') {
        $this->retrievedCheckout['id'] = 'cs_differentfixture';
    } elseif ($mismatch === 'reference') {
        $this->retrievedCheckout['attributes']['reference_number'] = (string) Str::uuid();
    } elseif ($mismatch === 'metadata') {
        $this->retrievedCheckout['attributes']['metadata']['order_id'] = (string) Str::uuid();
    } else {
        $this->retrievedCheckout['attributes']['livemode'] = true;
    }
    $this->artisan('billing:reconcile', ['--order' => $this->order->uuid, '--session' => 'cs_recoveryfixture'])->assertExitCode(1);
    expect($this->order->fresh()->checkout_session_id)->toBeNull()->and($this->order->fresh()->payment_status)->toBe('unknown');
    $this->assertDatabaseCount('subscriptions', 1);
})->with(['session', 'reference', 'metadata', 'mode']);

it('keeps retrieved paid data diagnostic until a signed paid event is processed', function () {
    $event = $this->paidCheckoutEvent($this->order->uuid, 'cs_recoveryfixture');
    $this->retrievedCheckout['attributes']['payments'] = $event['data']['attributes']['data']['attributes']['payments'];
    $this->artisan('billing:reconcile', ['--order' => $this->order->uuid, '--session' => 'cs_recoveryfixture'])->assertExitCode(0);
    expect($this->order->fresh()->payment_status)->toBe('pending')
        ->and($this->order->fresh()->issue_code)->toBe('awaiting_payment_webhook')
        ->and($this->order->fresh()->subscription_id)->toBeNull();
    $this->signedPayMongoEvent($event)->assertOk();
    $this->artisan('billing:reconcile', ['--order' => $this->order->uuid])->assertExitCode(0);
    expect($this->order->fresh()->payment_status)->toBe('paid')->and($this->order->fresh()->fulfillment_status)->toBe('fulfilled');
    $this->assertDatabaseCount('subscriptions', 2);
});

it('retries a previously verified paid activation without reprocessing or charging the checkout', function () {
    $this->order->update(['payment_status' => 'paid', 'payment_id' => 'pay_retryfixture', 'paid_at' => now(),
        'fulfillment_status' => 'needs_attention', 'issue_code' => 'activation_failed']);
    $this->artisan('billing:reconcile', ['--order' => $this->order->uuid])->assertExitCode(0);
    $subscriptionId = $this->order->fresh()->subscription_id;
    expect($subscriptionId)->not->toBeNull();
    $this->artisan('billing:reconcile', ['--order' => $this->order->uuid])->assertExitCode(0);
    expect($this->order->fresh()->subscription_id)->toBe($subscriptionId);
    $this->assertDatabaseCount('subscriptions', 2);
    Http::assertSentCount(1);
});

it('rejects malformed recovery options before contacting the payment provider', function () {
    $this->artisan('billing:reconcile', ['--session' => 'cs_recoveryfixture'])->assertExitCode(2);
    $this->artisan('billing:reconcile', ['--order' => 'not-a-uuid'])->assertExitCode(2);
    Http::assertSentCount(1);
});

it('does not starve later verified inbox entries when an earlier batch cannot find its order', function () {
    for ($index = 0; $index < 200; $index++) {
        $reference = (string) Str::uuid();
        $payload = $this->paidCheckoutEvent($reference, 'cs_missingorder'.$index);
        PayMongoWebhookEvent::create(['event_id' => 'evt_missingorder'.$index,
            'event_type' => 'checkout_session.payment.paid', 'livemode' => false,
            'checkout_session_id' => 'cs_missingorder'.$index, 'payload' => $payload['data']['attributes']['data'],
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'status' => 'received', 'received_at' => now()]);
    }
    $this->signedPayMongoEvent($this->paidCheckoutEvent($this->order->uuid, 'cs_recoveryfixture'))->assertOk();
    $this->artisan('billing:reconcile')->assertExitCode(0);
    expect($this->order->fresh()->payment_status)->toBe('paid')->and($this->order->fresh()->fulfillment_status)->toBe('fulfilled');
    $this->assertDatabaseCount('subscriptions', 2);
});
