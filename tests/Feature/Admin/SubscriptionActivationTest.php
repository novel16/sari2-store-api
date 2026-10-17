<?php

use App\Models\Admin\SubscriptionActivation;
use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\SavePlan;
use App\Services\Admin\Subscriptions\ActivateSubscription;
use App\Services\Auth\RegisterStoreOwner;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->store = $this->ownerStore($this->owner);
    $this->account = $this->store->account;
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->base = '/api/v1/admin/accounts/'.$this->account->id.'/subscription';
    $this->input = ['plan_id' => Plan::where('code', 'basic')->value('id'), 'months' => 1,
        'settlement_method' => 'manual_payment', 'amount_centavos' => 4900,
        'payment_reference' => ' receipt-001 ', 'payment_verified' => true, 'reason' => 'Payment checked in person'];
});

function reviewSubscription($test, array $overrides = []): array
{
    return $test->postJson($test->base.'/preview', array_replace($test->input, $overrides))->assertOk()->json('data');
}

function confirmSubscription($test, array $quote, ?string $key = null)
{
    return $test->postJson($test->base.'/activations', ['quote' => $quote['quote'], 'request_key' => $key ?? (string) Str::uuid()]);
}

it('protects subscription administration from guests owners and suspended administrators', function () {
    foreach ([$this->base, $this->base.'/history'] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
    $this->postJson($this->base.'/preview', $this->input)->assertUnauthorized();
    $this->postJson($this->base.'/activations', [])->assertUnauthorized();
    $this->actingAs($this->owner, 'web');
    $this->getJson($this->base)->assertForbidden();
    $this->getJson($this->base.'/history')->assertForbidden();
    $this->postJson($this->base.'/preview', $this->input)->assertForbidden();
    $this->postJson($this->base.'/activations', [])->assertForbidden();
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->admin->forceFill(['status' => 'suspended'])->save();
    $this->actingAs($this->admin, 'web')->postJson($this->base.'/activations', [])->assertForbidden();
});

it('requires a review and records a verified payment with immutable terms and actor history', function () {
    $this->actingAs($this->admin, 'web');
    $quote = reviewSubscription($this);
    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseCount('subscription_activations', 0);
    $quote['proposal']['terms']['monthly_price_centavos'] = 1; // Display data cannot alter the signed quote.
    confirmSubscription($this, $quote)->assertCreated()->assertJsonPath('data.amount_centavos', 4900)
        ->assertJsonPath('data.payment_reference', 'RECEIPT-001')->assertJsonPath('data.actor.id', $this->admin->id)
        ->assertJsonPath('data.snapshot.terms.active_product_limit', 500)->assertJsonPath('data.action', 'activate');
    $this->assertDatabaseHas('subscriptions', ['provider' => 'manual', 'plan_version_id' => Plan::find($this->input['plan_id'])->versions()->where('version', 1)->value('id'), 'renews_at' => null]);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.current.plan.code', 'basic');
    $this->getJson($this->base.'/history')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.quote_hash');
    $entry = SubscriptionActivation::firstOrFail();
    expect(fn () => $entry->update(['reason' => 'Rewritten']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('queues renewals without replacing current terms and switches periods exactly at expiry', function () {
    $this->travelTo(now()->setDate(2026, 10, 16)->setTime(4, 0));
    $this->actingAs($this->admin, 'web');
    $initial = reviewSubscription($this);
    confirmSubscription($this, $initial)->assertCreated();
    $plan = Plan::findOrFail($this->input['plan_id']);
    app(SavePlan::class)->handle(['monthly_price_centavos' => 5900, 'active_product_limit' => 600, 'expected_version' => $plan->version], $plan, $this->admin->id);
    $renewal = reviewSubscription($this, ['months' => 2, 'amount_centavos' => 11800, 'payment_reference' => 'renew-002']);
    expect($renewal['proposal']['starts_at'])->toBe($initial['proposal']['ends_at']);
    expect($renewal['proposal']['action'])->toBe('renew');
    confirmSubscription($this, $renewal)->assertCreated();
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.current.plan.monthly_price_centavos', 4900)
        ->assertJsonPath('data.renewal.next_period.plan.monthly_price_centavos', 5900)
        ->assertJsonPath('data.renewal.scheduled_periods', 1)->assertJsonPath('data.renewal.auto_renew', false);
    $this->travelTo(CarbonImmutable::parse($initial['proposal']['ends_at']));
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.current.plan.monthly_price_centavos', 5900)
        ->assertJsonPath('data.renewal.scheduled_periods', 0);
    $this->travelTo(CarbonImmutable::parse($renewal['proposal']['ends_at']));
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.current.plan.code', 'free')
        ->assertJsonPath('data.renewal.next_period', null);
    $this->assertDatabaseCount('subscriptions', 3);
});

it('uses Philippine calendar months at month end without overflowing into March', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-31T00:30:00+08:00'));
    $this->actingAs($this->admin, 'web');
    $quote = reviewSubscription($this);
    expect(CarbonImmutable::parse($quote['proposal']['ends_at'])->setTimezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2027-02-28 00:30');
    confirmSubscription($this, $quote)->assertCreated();
    $this->assertDatabaseHas('subscriptions', ['starts_at' => '2027-01-30 16:30:00', 'ends_at' => '2027-02-27 16:30:00']);
});

it('records complimentary periods with zero money and a reason', function () {
    $this->actingAs($this->admin, 'web');
    $input = [...$this->input, 'settlement_method' => 'complimentary', 'amount_centavos' => 0, 'reason' => 'One month launch promotion'];
    unset($input['payment_reference'], $input['payment_verified']);
    $quote = $this->postJson($this->base.'/preview', $input)->assertOk()->json('data');
    confirmSubscription($this, $quote)->assertCreated()->assertJsonPath('data.amount_centavos', 0)->assertJsonPath('data.payment_reference', null);
});

it('replays exactly once even after review expiry and rejects changed request keys or reused reviews', function () {
    $this->actingAs($this->admin, 'web');
    $quote = reviewSubscription($this);
    $key = (string) Str::uuid();
    $id = confirmSubscription($this, $quote, $key)->assertCreated()->json('data.id');
    $this->travel(10)->minutes();
    confirmSubscription($this, $quote, strtoupper($key))->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
    confirmSubscription($this, $quote)->assertConflict();
    $other = reviewSubscription($this, ['payment_reference' => 'another']);
    confirmSubscription($this, $other, $key)->assertConflict();
    $this->assertDatabaseCount('subscription_activations', 1);
    $this->assertDatabaseCount('subscriptions', 2);
});

it('rejects duplicate normalized payment references across accounts atomically', function () {
    $this->actingAs($this->admin, 'web');
    confirmSubscription($this, reviewSubscription($this))->assertCreated();
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->base = '/api/v1/admin/accounts/'.$this->ownerStore($other)->account_id.'/subscription';
    confirmSubscription($this, reviewSubscription($this, ['payment_reference' => ' RECEIPT-001 ']))->assertConflict();
    $this->assertDatabaseCount('subscription_activations', 1);
    $this->assertDatabaseCount('subscriptions', 3);
});

it('rejects expired reviews without writes', function () {
    $this->actingAs($this->admin, 'web');
    $quote = reviewSubscription($this);
    $this->travel(5)->minutes();
    confirmSubscription($this, $quote)->assertConflict();
    $this->assertDatabaseCount('subscriptions', 1);
});

it('rejects a stale catalog version and a competing renewal', function () {
    $this->actingAs($this->admin, 'web');
    $old = reviewSubscription($this);
    $plan = Plan::findOrFail($this->input['plan_id']);
    app(SavePlan::class)->handle(['monthly_price_centavos' => 5900, 'expected_version' => 1], $plan);
    confirmSubscription($this, $old)->assertConflict();
    $first = reviewSubscription($this, ['amount_centavos' => 5900]);
    $second = reviewSubscription($this, ['amount_centavos' => 5900, 'payment_reference' => 'second']);
    confirmSubscription($this, $first)->assertCreated();
    confirmSubscription($this, $second)->assertConflict();
    $this->assertDatabaseCount('subscription_activations', 1);
});

it('binds reviews to the account administrator and untampered token', function () {
    $this->actingAs($this->admin, 'web');
    $quote = reviewSubscription($this);
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->postJson('/api/v1/admin/accounts/'.$this->ownerStore($other)->account_id.'/subscription/activations', ['quote' => $quote['quote'], 'request_key' => (string) Str::uuid()])->assertConflict();
    confirmSubscription($this, ['quote' => $quote['quote'].'corrupt'])->assertUnprocessable()->assertJsonValidationErrors('quote');
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->actingAs(User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']), 'web');
    confirmSubscription($this, $quote)->assertConflict();
    $this->assertDatabaseCount('subscription_activations', 0);
});

it('rejects unsupported plans and retired Basic', function (string $code) {
    $this->actingAs($this->admin, 'web');
    $this->postJson($this->base.'/preview', [...$this->input, 'plan_id' => Plan::where('code', $code)->value('id')])->assertUnprocessable()->assertJsonValidationErrors('subscription');
})->with(['free', 'plus', 'pro']);

it('rechecks account eligibility when a reviewed account is suspended', function () {
    $this->actingAs($this->admin, 'web');
    $quote = reviewSubscription($this);
    $this->account->update(['status' => 'suspended']);
    confirmSubscription($this, $quote)->assertUnprocessable();
    $this->assertDatabaseCount('subscription_activations', 0);
});

it('rejects hidden Basic incompatible memberships and open ended paid periods', function () {
    $this->actingAs($this->admin, 'web');
    Plan::whereKey($this->input['plan_id'])->update(['catalog_status' => 'retired']);
    $this->postJson($this->base.'/preview', $this->input)->assertUnprocessable();
    Plan::whereKey($this->input['plan_id'])->update(['catalog_status' => 'published']);
    $branch = $this->account->stores()->create(['name' => 'Branch', 'slug' => 'branch', 'status' => 'active']);
    $this->postJson($this->base.'/preview', $this->input)->assertUnprocessable();
    $branch->delete();
    $member = $this->account->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'cashier', 'status' => 'active']);
    $this->postJson($this->base.'/preview', $this->input)->assertUnprocessable();
    $member->delete();
    $this->account->subscriptions()->create(['plan_id' => $this->input['plan_id'], 'starts_at' => now(), 'status' => 'active']);
    $this->postJson($this->base.'/preview', $this->input)->assertUnprocessable();
});

it('rolls back the paid period when its audit write fails', function () {
    $this->actingAs($this->admin, 'web');
    $quote = reviewSubscription($this);
    SubscriptionActivation::creating(fn () => throw new RuntimeException('Simulated audit failure'));
    try {
        expect(fn () => app(ActivateSubscription::class)->handle($this->account, $this->admin,
            ['quote' => $quote['quote'], 'request_key' => (string) Str::uuid()]))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.SubscriptionActivation::class);
    }
    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseCount('subscription_activations', 0);
    confirmSubscription($this, $quote)->assertCreated();
});

it('validates payment evidence exact amounts duration and server controlled fields', function (array $overrides, string $field) {
    $this->actingAs($this->admin, 'web');
    $this->postJson($this->base.'/preview', array_replace($this->input, $overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['amount_centavos' => 4800], 'amount_centavos'], [['amount_centavos' => 5000], 'amount_centavos'],
    [['amount_centavos' => 49.5], 'amount_centavos'], [['months' => 0], 'months'], [['months' => 13], 'months'],
    [['payment_reference' => ''], 'payment_reference'], [['payment_reference' => 'bad reference'], 'payment_reference'],
    [['payment_verified' => false], 'payment_verified'], [['reason' => ' '], 'reason'],
    [['actor_user_id' => 999], 'actor_user_id'], [['ends_at' => '2099-01-01'], 'ends_at'],
]);

it('keeps history and subscription overview read queries constant as periods accumulate', function () {
    $this->actingAs($this->admin, 'web');
    confirmSubscription($this, reviewSubscription($this))->assertCreated();
    confirmSubscription($this, reviewSubscription($this, ['payment_reference' => 'baseline-renewal']))->assertCreated();
    $small = $this->readQueries(fn () => $this->getJson($this->base.'/history')->assertOk());
    $smallStatus = $this->readQueries(fn () => $this->getJson($this->base)->assertOk());
    for ($i = 0; $i < 7; $i++) {
        confirmSubscription($this, reviewSubscription($this, ['payment_reference' => 'receipt-'.$i]))->assertCreated();
    }
    $large = $this->readQueries(fn () => $this->getJson($this->base.'/history')->assertOk()->assertJsonCount(9, 'data'));
    $largeStatus = $this->readQueries(fn () => $this->getJson($this->base)->assertOk()->assertJsonPath('data.renewal.scheduled_periods', 8));
    expect(count($large))->toBe(count($small));
    expect(count($largeStatus))->toBe(count($smallStatus));
});
