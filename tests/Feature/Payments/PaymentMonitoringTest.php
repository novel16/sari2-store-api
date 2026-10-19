<?php

use App\Models\Billing\SubscriptionOrder;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\RegisterStoreOwner;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    config(['paymongo.enabled' => true, 'paymongo.mode' => 'test', 'paymongo.secret_key' => 'sk_test_fixtureonly',
        'paymongo.webhook_secret' => 'whsec_fixture_only']);
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $store = $this->ownerStore($this->owner);
    $this->account = $store->account;
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response(['data' => ['id' => 'cs_monitoringfixture',
        'type' => 'checkout_session', 'attributes' => ['livemode' => false,
            'checkout_url' => 'https://checkout.paymongo.com/cs_monitoringfixture']]], 200));
    $basic = Plan::where('code', 'basic')->firstOrFail();
    $this->actingAs($this->owner, 'web')->postJson('/api/v1/stores/'.$store->id.'/subscription/orders', [
        'request_key' => (string) Str::uuid(), 'plan_id' => $basic->id, 'expected_plan_version' => $basic->version, 'months' => 1,
    ])->assertCreated();
    $this->order = SubscriptionOrder::firstOrFail();
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->withHeader('Origin', 'http://localhost:3001');
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->base = '/api/v1/admin/subscription-payments';
});

it('restricts platform payment monitoring to current active administrators', function () {
    $this->getJson($this->base)->assertUnauthorized();
    $this->actingAs($this->owner, 'web')->getJson($this->base)->assertForbidden();
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->admin->forceFill(['status' => 'suspended'])->save();
    $this->actingAs($this->admin, 'web')->getJson($this->base)->assertForbidden();
});

it('shows account owner and payment states while withholding checkout links and internal evidence', function () {
    $response = $this->actingAs($this->admin, 'web')->getJson($this->base)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->order->uuid)
        ->assertJsonPath('data.0.account.id', $this->account->id)
        ->assertJsonPath('data.0.owner.email', $this->owner->email)
        ->assertJsonPath('data.0.checkout_url', null);
    foreach (['payload', 'request_key', 'request_hash', 'webhook_events', 'active_account_id'] as $private) {
        $response->assertJsonMissingPath('data.0.'.$private);
    }
    expect($response->getContent())->not->toContain('https://checkout.paymongo.com/')
        ->not->toContain('sk_test_fixtureonly')->not->toContain('whsec_fixture_only');
    Http::assertSentCount(1);
});

it('searches accounts and combines settlement and activation filters', function () {
    $this->order->replicate()->forceFill(['uuid' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
        'active_account_id' => null, 'checkout_session_id' => null, 'payment_id' => 'pay_needsattention',
        'payment_status' => 'paid', 'fulfillment_status' => 'needs_attention', 'issue_code' => 'account_unavailable'])->save();
    $this->actingAs($this->admin, 'web');
    $this->getJson($this->base.'?payment_status=paid&fulfillment_status=needs_attention')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payment_status', 'paid')->assertJsonPath('data.0.issue_code', 'account_unavailable');
    $this->getJson($this->base.'?payment_status=pending&fulfillment_status=needs_attention')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->base.'?q='.urlencode($this->owner->email))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($this->base.'?q=unmatched-owner-fixture')->assertOk()->assertJsonCount(0, 'data');
    Http::assertSentCount(1);
});

it('keeps payment monitoring queries constant as the returned history grows', function () {
    for ($index = 0; $index < 20; $index++) {
        $this->order->replicate()->forceFill(['uuid' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'active_account_id' => null, 'checkout_session_id' => null, 'payment_id' => null,
            'payment_status' => 'failed', 'fulfillment_status' => 'pending'])->save();
    }
    $this->actingAs($this->admin, 'web');
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($this->base.'?per_page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 21),
        fn () => $this->getJson($this->base.'?per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21),
    );
    Http::assertSentCount(1);
});

it('validates payment monitoring filters instead of accepting arbitrary query shapes', function (string $query, string $field) {
    $this->actingAs($this->admin, 'web')->getJson($this->base.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['payment_status=refunded', 'payment_status'], ['fulfillment_status=active', 'fulfillment_status'],
    ['per_page=101', 'per_page'], ['q='.str_repeat('a', 121), 'q'],
]);
