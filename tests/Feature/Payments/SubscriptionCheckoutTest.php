<?php

use App\Models\Billing\PayMongoWebhookEvent;
use App\Models\Billing\SubscriptionOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\SavePlan;
use App\Services\Auth\RegisterStoreOwner;
use App\Services\Billing\ProcessPayMongoWebhook;
use App\Services\Payments\PayMongo\PayMongoSession;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;
use Tests\Support\PayMongoPaymentFixtures;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class, PayMongoPaymentFixtures::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->store = $this->ownerStore($this->owner);
    $this->account = $this->store->account;
    $this->plan = Plan::where('code', 'basic')->firstOrFail();
    $this->base = '/api/v1/stores/'.$this->store->id.'/subscription/orders';
    $this->checkoutInput = ['request_key' => (string) Str::uuid(), 'plan_id' => $this->plan->id,
        'expected_plan_version' => $this->plan->version, 'months' => 1];
    config(['paymongo.enabled' => true, 'paymongo.mode' => 'test', 'paymongo.secret_key' => 'sk_test_fixtureonly',
        'paymongo.webhook_secret' => 'whsec_fixture_only', 'paymongo.checkout_lifetime_minutes' => 30]);
    Http::preventStrayRequests();
    $this->providerFails = false;
    $this->providerAttempts = 0;
    $this->beforeCheckoutReturns = null;
    Http::fake(function (Request $request) {
        $this->providerAttempts++;
        if ($this->providerFails) {
            throw new ConnectionException('Simulated timeout after sending the request.');
        }
        $uuid = $request['data']['attributes']['reference_number'];
        $checkoutId = 'cs_'.str_replace('-', '', $uuid);
        if ($this->beforeCheckoutReturns !== null) {
            ($this->beforeCheckoutReturns)($uuid, $checkoutId);
        }

        return Http::response(['data' => ['id' => $checkoutId,
            'type' => 'checkout_session', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_testfixture',
                'reference_number' => $uuid, 'metadata' => ['order_id' => $uuid], 'livemode' => false, 'status' => 'active']]], 200);
    });
});

it('requires a signed-in active store owner and never grants platform administrators store billing access', function () {
    $this->getJson($this->base)->assertUnauthorized();
    $this->postJson($this->base, $this->checkoutInput)->assertUnauthorized();
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->actingAs($other, 'web')->postJson($this->base, $this->checkoutInput)->assertNotFound();
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->actingAs($admin, 'web')->getJson($this->base)->assertNotFound();
    $this->postJson($this->base, $this->checkoutInput)->assertNotFound();
    Http::assertNothingSent();
    $this->assertDatabaseCount('subscription_orders', 0);
});

it('builds QR Ph checkout from saved Basic terms without granting coverage before payment', function () {
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)
        ->assertCreated()->assertJsonPath('replayed', false)->assertJsonPath('data.amount_centavos', 4900)
        ->assertJsonPath('data.payment_status', 'pending');
    $order = SubscriptionOrder::firstOrFail();
    expect($order->plan_version_id)->toBe($this->plan->versions()->where('version', 1)->value('id'))
        ->and($order->terms['active_product_limit'])->toBe(500)
        ->and($order->active_account_id)->toBe($this->account->id)
        ->and($order->payment_id)->toBeNull()->and($order->paid_at)->toBeNull();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['data']['attributes']['payment_method_types'] === ['qrph']
        && $request['data']['attributes']['line_items'][0]['amount'] === 4900
        && $request['data']['attributes']['reference_number'] === $order->uuid
        && $request['data']['attributes']['success_url'] === rtrim(config('app.frontend_url'), '/').'/subscription/payments/'.$order->uuid.'?payment=return'
        && $request['data']['attributes']['cancel_url'] === rtrim(config('app.frontend_url'), '/').'/subscription/payments/'.$order->uuid.'?payment=back');
    $this->assertDatabaseCount('subscriptions', 1);
    $this->getJson('/api/v1/stores/'.$this->store->id.'/subscription')->assertOk()->assertJsonPath('data.current.plan.code', 'free');
});

it('prohibits client-controlled money payment and coverage fields', function (array $extra, string $field) {
    $this->actingAs($this->owner, 'web')->postJson($this->base, [...$this->checkoutInput, ...$extra])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    Http::assertNothingSent();
    $this->assertDatabaseCount('subscription_orders', 0);
})->with([
    [['amount_centavos' => 1], 'amount_centavos'], [['currency' => 'USD'], 'currency'],
    [['payment_status' => 'paid'], 'payment_status'], [['status' => 'paid'], 'status'],
    [['starts_at' => '2020-01-01'], 'starts_at'], [['ends_at' => '2099-01-01'], 'ends_at'],
    [['checkout_session_id' => 'cs_forged'], 'checkout_session_id'], [['payment_id' => 'pay_forged'], 'payment_id'],
]);

it('rejects unsupported plans without creating a provider checkout', function (string $code) {
    $plan = Plan::where('code', $code)->firstOrFail();
    $this->actingAs($this->owner, 'web')->postJson($this->base, [...$this->checkoutInput,
        'plan_id' => $plan->id, 'expected_plan_version' => $plan->version])->assertUnprocessable();
    Http::assertNothingSent();
    $this->assertDatabaseCount('subscription_orders', 0);
})->with(['free', 'plus', 'pro']);

it('rejects stale prices and validates duration before calling PayMongo', function () {
    $this->actingAs($this->owner, 'web');
    foreach ([0, 13, '1.5'] as $months) {
        $this->postJson($this->base, [...$this->checkoutInput, 'months' => $months])->assertUnprocessable()->assertJsonValidationErrors('months');
    }
    app(SavePlan::class)->handle(['monthly_price_centavos' => 5900, 'expected_version' => 1], $this->plan);
    $this->postJson($this->base, $this->checkoutInput)->assertConflict();
    Http::assertNothingSent();
    $this->assertDatabaseCount('subscription_orders', 0);
});

it('replays a request key and rejects changed details or another unresolved order', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson($this->base, $this->checkoutInput)->assertCreated()->json('data.id');
    $this->postJson($this->base, $this->checkoutInput)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
    $this->postJson($this->base, [...$this->checkoutInput, 'months' => 2])->assertConflict();
    $this->postJson($this->base, [...$this->checkoutInput, 'request_key' => (string) Str::uuid()])->assertConflict();
    Http::assertSentCount(1);
    $this->assertDatabaseCount('subscription_orders', 1);
});

it('blocks another payment and exposes a paid activation needing attention even without an active checkout slot', function () {
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)->assertCreated();
    $order = SubscriptionOrder::firstOrFail();
    $order->update(['payment_status' => 'paid', 'payment_id' => 'pay_awaitingactivation', 'paid_at' => now(),
        'fulfillment_status' => 'needs_attention', 'issue_code' => 'activation_failed', 'active_account_id' => null]);
    $this->postJson($this->base, [...$this->checkoutInput, 'request_key' => (string) Str::uuid()])->assertConflict()
        ->assertJsonPath('code', 'subscription_checkout_pending');
    $this->getJson('/api/v1/stores/'.$this->store->id.'/subscription')->assertOk()
        ->assertJsonPath('data.pending_order.id', $order->uuid)->assertJsonPath('data.pending_order.payment_status', 'paid')
        ->assertJsonPath('data.pending_order.fulfillment_status', 'needs_attention')->assertJsonPath('data.pending_order.checkout_url', null);
    $this->assertDatabaseCount('subscription_orders', 1);
    $this->assertDatabaseCount('subscriptions', 1);
    Http::assertSentCount(1);
});

it('keeps an uncertain create attempt and never sends another checkout on a blind retry', function () {
    $this->providerFails = true;
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)
        ->assertStatus(202)->assertJsonPath('data.payment_status', 'unknown');
    $this->postJson($this->base, $this->checkoutInput)->assertSuccessful()->assertJsonPath('replayed', true);
    $this->postJson($this->base, [...$this->checkoutInput, 'request_key' => (string) Str::uuid()])->assertConflict();
    expect($this->providerAttempts)->toBe(1);
    $this->assertDatabaseCount('subscription_orders', 1);
    $this->assertDatabaseCount('subscriptions', 1);
});

it('preserves a payment settled by a fast webhook before checkout creation returns', function () {
    $this->beforeCheckoutReturns = function (string $uuid, string $checkoutId) {
        $payload = $this->paidCheckoutEvent($uuid, $checkoutId);
        $session = app(PayMongoSession::class)->normalize($payload['data']['attributes']['data']);
        // Model the committed, signature-verified inbox while the create HTTP response is still in flight.
        $event = PayMongoWebhookEvent::create(['event_id' => 'evt_fastcallback',
            'event_type' => 'checkout_session.payment.paid', 'livemode' => false,
            'checkout_session_id' => $checkoutId, 'payload' => $session,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'status' => 'received', 'received_at' => now()]);
        app(ProcessPayMongoWebhook::class)->handle($event->id);
    };
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)
        ->assertCreated()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.fulfillment_status', 'fulfilled');
    expect(SubscriptionOrder::firstOrFail()->active_account_id)->toBeNull();
    $this->assertDatabaseCount('subscriptions', 2);
    Http::assertSentCount(1);
});

it('does not activate from return query parameters or a read-only status request', function () {
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)->assertCreated();
    $order = SubscriptionOrder::firstOrFail();
    Http::assertSentCount(1);
    foreach ([$this->base.'/'.$order->uuid.'?status=paid&payment_status=paid',
        $this->base.'/by-key/'.$this->checkoutInput['request_key'], $this->base] as $url) {
        $this->getJson($url)->assertOk();
    }
    expect($order->fresh()->payment_status)->toBe('pending')->and($order->fresh()->subscription_id)->toBeNull();
    Http::assertSentCount(1);
    $this->assertDatabaseCount('subscriptions', 1);
});

it('keeps individual orders request keys and history isolated across accounts', function () {
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)->assertCreated();
    $order = SubscriptionOrder::firstOrFail();
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $otherBase = '/api/v1/stores/'.$this->ownerStore($other)->id.'/subscription/orders';
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->actingAs($other, 'web');
    $this->getJson($otherBase.'/'.$order->uuid)->assertNotFound();
    $this->getJson($otherBase.'/by-key/'.$order->request_key)->assertNotFound();
    $this->postJson($otherBase.'/'.$order->uuid.'/expire')->assertNotFound();
    $this->getJson($otherBase)->assertOk()->assertJsonCount(0, 'data');
    Http::assertSentCount(1);
});

it('fails closed when checkout is disabled or the provider is not configured', function (array $configuration) {
    config($configuration);
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)->assertStatus(503);
    Http::assertNothingSent();
    $this->assertDatabaseCount('subscription_orders', 0);
})->with([
    [['paymongo.enabled' => false]], [['paymongo.secret_key' => '']], [['paymongo.webhook_secret' => '']],
]);

it('stores checkout links encrypted and only exposes the owner-safe order projection', function () {
    $response = $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)->assertCreated();
    $order = SubscriptionOrder::firstOrFail();
    expect($order->getRawOriginal('checkout_url'))->not->toContain('https://checkout.paymongo.com/');
    foreach (['request_hash', 'active_account_id', 'owner_user_id', 'payload', 'secret_key', 'webhook_secret'] as $private) {
        $response->assertJsonMissingPath('data.'.$private);
    }
    expect($response->getContent())->not->toContain('sk_test_fixtureonly')->not->toContain('whsec_fixture_only');
});

it('paginates immutable order history with a constant number of batched database reads', function () {
    $this->actingAs($this->owner, 'web')->postJson($this->base, $this->checkoutInput)->assertCreated();
    $template = SubscriptionOrder::firstOrFail();
    for ($index = 0; $index < 20; $index++) {
        $subscription = Subscription::create(['account_id' => $this->account->id, 'plan_id' => $this->plan->id,
            'plan_version_id' => $template->plan_version_id, 'status' => 'active',
            'starts_at' => now()->subMonths($index + 2), 'ends_at' => now()->subMonths($index + 1), 'provider' => 'paymongo']);
        $template->replicate()->forceFill(['uuid' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'active_account_id' => null, 'checkout_session_id' => null, 'payment_id' => 'pay_history'.$index,
            'payment_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'subscription_id' => $subscription->id,
            'paid_at' => now()->subMonths($index + 2), 'fulfilled_at' => now()->subMonths($index + 2)])->save();
    }
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($this->base.'?per_page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 21),
        fn () => $this->getJson($this->base.'?per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21),
    );
    Http::assertSentCount(1);
});
