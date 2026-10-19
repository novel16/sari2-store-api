<?php

use App\Jobs\Billing\ProcessPayMongoEvent;
use App\Models\Billing\PayMongoWebhookEvent;
use App\Models\Billing\SubscriptionOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Admin\SavePlan;
use App\Services\Auth\RegisterStoreOwner;
use App\Services\Billing\FulfillSubscriptionOrder;
use App\Services\Billing\ProcessPayMongoWebhook;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\AuthTestHelpers;
use Tests\Support\PayMongoPaymentFixtures;

uses(AuthTestHelpers::class, PayMongoPaymentFixtures::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-18T04:00:00Z'));
    config(['paymongo.enabled' => true, 'paymongo.mode' => 'test', 'paymongo.secret_key' => 'sk_test_fixtureonly',
        'paymongo.webhook_secret' => 'whsec_fixture_only', 'paymongo.signature_tolerance' => 300,
        'paymongo.checkout_lifetime_minutes' => 30]);
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->store = $this->ownerStore($this->owner);
    $this->account = $this->store->account;
    $this->plan = Plan::where('code', 'basic')->firstOrFail();
    $this->base = '/api/v1/stores/'.$this->store->id.'/subscription/orders';
    $this->input = ['request_key' => (string) Str::uuid(), 'plan_id' => $this->plan->id,
        'expected_plan_version' => $this->plan->version, 'months' => 1];
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $uuid = $request['data']['attributes']['reference_number'];

        return Http::response(['data' => ['id' => 'cs_'.str_replace('-', '', $uuid),
            'type' => 'checkout_session', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_testfixture',
                'reference_number' => $uuid, 'metadata' => ['order_id' => $uuid], 'livemode' => false, 'status' => 'active']]], 200);
    });
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->input)->assertCreated();
    $this->order = SubscriptionOrder::firstOrFail();
    $this->event = $this->paidCheckoutEvent($this->order->uuid, $this->order->checkout_session_id);
    Queue::fake();
    // A provider callback is not an owner's browser request and must work without its cookie or Origin.
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->withoutHeader('Origin');
});

function processPaymentInbox(): void
{
    foreach (PayMongoWebhookEvent::orderBy('id')->get() as $event) {
        app(ProcessPayMongoWebhook::class)->handle($event->id);
    }
}

it('accepts a signed public callback durably and grants one period only after processing', function () {
    $this->signedPayMongoEvent($this->event)->assertOk();
    $this->assertDatabaseCount('paymongo_webhook_events', 1);
    Queue::assertPushed(ProcessPayMongoEvent::class);
    expect($this->order->fresh()->payment_status)->toBe('pending');
    $this->assertDatabaseCount('subscriptions', 1);
    processPaymentInbox();
    $order = $this->order->fresh();
    expect($order->payment_status)->toBe('paid')->and($order->fulfillment_status)->toBe('fulfilled')
        ->and($order->payment_id)->toBe('pay_paid001')->and($order->paid_at)->not->toBeNull()
        ->and($order->subscription_id)->not->toBeNull()->and($order->active_account_id)->toBeNull();
    $this->assertDatabaseHas('subscriptions', ['id' => $order->subscription_id, 'provider' => 'paymongo',
        'plan_version_id' => $order->plan_version_id]);
    $this->assertDatabaseCount('subscriptions', 2);
});

it('rejects unsigned bad-secret stale future and raw-body-tampered callbacks', function (string $kind) {
    $body = json_encode($this->event, JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $secret = 'whsec_fixture_only';
    $signature = null;
    if ($kind === 'unsigned') {
        $signature = '';
    } elseif ($kind === 'bad-secret') {
        $secret = 'wrong_secret';
    } elseif ($kind === 'stale') {
        $timestamp -= 301;
    } elseif ($kind === 'future') {
        $timestamp += 301;
    } else {
        $signature = 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$body, $secret).',li=';
        $body .= ' ';
    }
    $this->rawPayMongoEvent($body, $timestamp, $secret, $signature)->assertUnauthorized();
    $this->assertDatabaseCount('paymongo_webhook_events', 0);
    $this->assertDatabaseCount('subscriptions', 1);
    Queue::assertNothingPushed();
})->with(['unsigned', 'bad-secret', 'stale', 'future', 'tampered']);

it('rejects a callback signed for the wrong mode and malformed JSON', function () {
    $body = json_encode($this->event, JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $signature = 't='.$timestamp.',te=,li='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture_only');
    $this->rawPayMongoEvent($body, $timestamp, null, $signature)->assertUnauthorized();
    $this->rawPayMongoEvent('{broken-json')->assertStatus(400);
    $this->assertDatabaseCount('paymongo_webhook_events', 0);
    Queue::assertNothingPushed();
});

it('fails closed when no webhook signing secret is configured', function () {
    config(['paymongo.webhook_secret' => '']);
    $this->signedPayMongoEvent($this->event, null, 'whsec_fixture_only')->assertStatus(503);
    $this->assertDatabaseCount('paymongo_webhook_events', 0);
});

it('acknowledges duplicate deliveries and repeated jobs without granting a second period', function () {
    $this->signedPayMongoEvent($this->event)->assertOk();
    $this->signedPayMongoEvent($this->event)->assertOk();
    processPaymentInbox();
    processPaymentInbox();
    $samePayment = $this->event;
    $samePayment['data']['id'] = 'evt_samepaymentagain';
    $this->signedPayMongoEvent($samePayment)->assertOk();
    processPaymentInbox();
    $this->assertDatabaseCount('paymongo_webhook_events', 2);
    $this->assertDatabaseCount('subscriptions', 2);
    expect($this->order->fresh()->fulfillment_status)->toBe('fulfilled');
});

it('rejects changed bytes under the same event identity without overwriting verified evidence', function () {
    $this->signedPayMongoEvent($this->event)->assertOk();
    $changed = $this->event;
    $changed['data']['attributes']['data']['attributes']['payments'][0]['attributes']['amount'] = 1;
    $this->signedPayMongoEvent($changed)->assertConflict()->assertJsonPath('code', 'webhook_identity_conflict');
    $this->assertDatabaseCount('paymongo_webhook_events', 1);
    processPaymentInbox();
    expect($this->order->fresh()->payment_status)->toBe('paid')->and($this->order->fresh()->amount_centavos)->toBe(4900);
    $this->assertDatabaseCount('subscriptions', 2);
});

it('acknowledges unsupported signed event types without attempting subscription activation', function () {
    $this->event['data']['attributes']['type'] = 'payment.refunded';
    $this->signedPayMongoEvent($this->event)->assertOk();
    Queue::assertNothingPushed();
    expect(PayMongoWebhookEvent::firstOrFail()->status)->toBe('ignored');
    $this->assertDatabaseCount('subscriptions', 1);
    expect($this->order->fresh()->payment_status)->toBe('pending');
});

it('retains verified evidence when queue dispatch fails so a worker can recover it without another charge', function () {
    $this->mock(Dispatcher::class, function (MockInterface $mock) {
        $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Simulated queue outage'));
    });
    $this->signedPayMongoEvent($this->event)->assertStatus(503)->assertJsonPath('code', 'webhook_processing_retry');
    expect(PayMongoWebhookEvent::firstOrFail()->status)->toBe('received')
        ->and($this->order->fresh()->payment_status)->toBe('pending');
    processPaymentInbox();
    expect($this->order->fresh()->payment_status)->toBe('paid')->and($this->order->fresh()->fulfillment_status)->toBe('fulfilled');
    $this->assertDatabaseCount('subscriptions', 2);
    Http::assertSentCount(1);
});

it('retains a late paid fact after expiry even when a replacement checkout owns the unresolved slot', function () {
    $this->order->update(['payment_status' => 'expired', 'active_account_id' => null]);
    $replacement = $this->order->replicate();
    $replacement->forceFill(['uuid' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
        'checkout_session_id' => 'cs_replacementcheckout', 'payment_status' => 'pending',
        'active_account_id' => $this->account->id])->save();
    $this->signedPayMongoEvent($this->event)->assertOk();
    processPaymentInbox();
    expect($this->order->fresh()->payment_status)->toBe('paid')->and($this->order->fresh()->fulfillment_status)->toBe('fulfilled')
        ->and($replacement->fresh()->payment_status)->toBe('pending')
        ->and($replacement->fresh()->active_account_id)->toBe($this->account->id);
    expect(PayMongoWebhookEvent::firstOrFail()->status)->toBe('processed');
    $this->assertDatabaseCount('subscriptions', 2);
});

it('does not trust a paid notification with mismatched money reference mode or source', function (array $override, int $status = 200) {
    $payload = array_replace_recursive($this->event, $override);
    $this->signedPayMongoEvent($payload)->assertStatus($status);
    processPaymentInbox();
    expect($this->order->fresh()->payment_status)->not->toBe('paid')
        ->and($this->order->fresh()->subscription_id)->toBeNull();
    $this->assertDatabaseCount('subscriptions', 1);
})->with([
    'wrong amount' => [['data' => ['attributes' => ['data' => ['attributes' => ['payments' => [['attributes' => ['amount' => 1]]]]]]]]],
    'wrong currency' => [['data' => ['attributes' => ['data' => ['attributes' => ['payments' => [['attributes' => ['currency' => 'USD']]]]]]]]],
    'unpaid source' => [['data' => ['attributes' => ['data' => ['attributes' => ['payments' => [['attributes' => ['status' => 'pending']]]]]]]]],
    'card source' => [['data' => ['attributes' => ['data' => ['attributes' => ['payments' => [['attributes' => ['source' => ['type' => 'card']]]]]]]]]],
    'wrong reference' => [['data' => ['attributes' => ['data' => ['attributes' => ['reference_number' => (string) Str::uuid()]]]]]],
    'live event' => [['data' => ['attributes' => ['livemode' => true]]], 400],
    'live session' => [['data' => ['attributes' => ['data' => ['attributes' => ['livemode' => true]]]]], 400],
    'live payment' => [['data' => ['attributes' => ['data' => ['attributes' => ['payments' => [['attributes' => ['livemode' => true]]]]]]]]],
]);

it('retains a paid fact when fulfillment fails and retries exactly once', function () {
    $this->signedPayMongoEvent($this->event)->assertOk();
    Subscription::creating(function (Subscription $subscription) {
        if ($subscription->provider === 'paymongo') {
            throw new RuntimeException('Simulated temporary subscription write failure');
        }
    });
    try {
        expect(fn () => processPaymentInbox())->toThrow(RuntimeException::class, 'Subscription activation needs a retry.');
    } finally {
        Event::forget('eloquent.creating: '.Subscription::class);
    }
    $order = $this->order->fresh();
    expect($order->payment_status)->toBe('paid')->and($order->subscription_id)->toBeNull();
    $this->assertDatabaseCount('subscriptions', 1);
    app(FulfillSubscriptionOrder::class)->handle($order);
    app(FulfillSubscriptionOrder::class)->handle($order->fresh());
    expect($order->fresh()->fulfillment_status)->toBe('fulfilled');
    $this->assertDatabaseCount('subscriptions', 2);
});

it('keeps suspended accounts paid but awaiting attention and grants the full period after eligibility returns', function (string $target) {
    ($target === 'account' ? $this->account : $this->owner)->forceFill(['status' => 'suspended'])->save();
    $this->signedPayMongoEvent($this->event)->assertOk();
    processPaymentInbox();
    $order = $this->order->fresh();
    expect($order->payment_status)->toBe('paid')->and($order->fulfillment_status)->toBe('needs_attention')
        ->and($order->issue_code)->toBe('account_unavailable')->and($order->subscription_id)->toBeNull();
    $this->travel(2)->days();
    ($target === 'account' ? $this->account : $this->owner)->forceFill(['status' => 'active'])->save();
    app(FulfillSubscriptionOrder::class)->handle($order->fresh());
    $subscription = Subscription::findOrFail($order->fresh()->subscription_id);
    expect($subscription->starts_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($subscription->ends_at->toIso8601String())->toBe(now()->addMonthNoOverflow()->toIso8601String());
    $this->assertDatabaseCount('subscriptions', 2);
})->with(['account', 'owner']);

it('honors the checkout price and plan version after a catalog price or availability change', function () {
    app(SavePlan::class)->handle(['monthly_price_centavos' => 5900, 'active_product_limit' => 600,
        'catalog_status' => 'retired', 'expected_version' => 1], $this->plan);
    $this->signedPayMongoEvent($this->event)->assertOk();
    processPaymentInbox();
    $order = $this->order->fresh();
    expect($order->amount_centavos)->toBe(4900)->and($order->terms['active_product_limit'])->toBe(500)
        ->and($order->fulfillment_status)->toBe('fulfilled');
    $this->assertDatabaseHas('subscriptions', ['id' => $order->subscription_id, 'plan_version_id' => $order->plan_version_id]);
});

it('appends a purchased renewal after all existing prepaid coverage using Philippine calendar months', function () {
    $firstEnd = CarbonImmutable::parse('2027-01-31T00:30:00+08:00')->utc();
    $this->account->subscriptions()->create(['plan_id' => $this->plan->id,
        'plan_version_id' => $this->order->plan_version_id, 'status' => 'active', 'starts_at' => now()->subDay(),
        'ends_at' => $firstEnd->subMonthNoOverflow(), 'provider' => 'manual']);
    $this->account->subscriptions()->create(['plan_id' => $this->plan->id,
        'plan_version_id' => $this->order->plan_version_id, 'status' => 'active',
        'starts_at' => $firstEnd->subMonthNoOverflow(), 'ends_at' => $firstEnd, 'provider' => 'manual']);
    $this->signedPayMongoEvent($this->event)->assertOk();
    processPaymentInbox();
    $subscription = Subscription::findOrFail($this->order->fresh()->subscription_id);
    expect($subscription->starts_at->toIso8601String())->toBe($firstEnd->toIso8601String())
        ->and($subscription->ends_at->setTimezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2027-02-28 00:30');
    $this->assertDatabaseCount('subscriptions', 4);
});

it('stores provider evidence encrypted without exposing raw payloads through owner order responses', function () {
    $this->signedPayMongoEvent($this->event)->assertOk();
    $inbox = PayMongoWebhookEvent::firstOrFail();
    expect($inbox->getRawOriginal('payload'))->not->toContain('pay_paid001')->not->toContain($this->order->uuid);
    $this->withHeader('Origin', 'http://localhost:3000')->actingAs($this->owner, 'web');
    $this->getJson($this->base.'/'.$this->order->uuid)->assertOk()
        ->assertJsonMissingPath('data.payload')->assertJsonMissingPath('data.request_hash')
        ->assertJsonMissingPath('data.webhook_events');
});
