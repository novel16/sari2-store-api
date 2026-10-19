<?php

use App\Jobs\Billing\ProcessPayMongoEvent;
use App\Models\Billing\PayMongoWebhookEvent;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\EnforceCsrfInTests;
use Tests\Support\PayMongoPaymentFixtures;

uses(AuthTestHelpers::class, PayMongoPaymentFixtures::class);

beforeEach(function () {
    config(['paymongo.mode' => 'test', 'paymongo.webhook_secret' => 'whsec_fixture_only']);
    // Exercise Sanctum's real CSRF middleware rather than Laravel's test bypass.
    $this->app->bind(ValidateCsrfToken::class, EnforceCsrfInTests::class);
    Queue::fake();
    Http::preventStrayRequests();
});

it('authenticates a webhook by signature even when a stateful Origin triggers Sanctums nested middleware', function (string $origin) {
    $this->withHeader('Origin', $origin)->withCredentials();
    $event = $this->paidCheckoutEvent('3f67f85c-f08e-4f26-bde0-98411f1fa1f0', 'cs_CsrfFixture');
    $this->signedPayMongoEvent($event)->assertOk()->assertJson(['received' => true]);
    expect(PayMongoWebhookEvent::firstOrFail()->status)->toBe('received');
    Queue::assertPushed(ProcessPayMongoEvent::class);
    Http::assertNothingSent();
})->with(['http://localhost:3000', 'http://localhost:3001']);

it('still requires the provider signature on the exempt webhook path', function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $body = json_encode($this->paidCheckoutEvent('3f67f85c-f08e-4f26-bde0-98411f1fa1f0', 'cs_CsrfFixture'), JSON_THROW_ON_ERROR);
    $this->rawPayMongoEvent($body, null, null, '')->assertUnauthorized()
        ->assertJson(['code' => 'invalid_webhook_signature']);
    $this->assertDatabaseCount('paymongo_webhook_events', 0);
    Queue::assertNothingPushed();
});

it('retains CSRF protection on ordinary authentication and subscription writes', function (string $path) {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->postJson($path, [])->assertStatus(419);
    $this->assertDatabaseCount('paymongo_webhook_events', 0);
    Queue::assertNothingPushed();
})->with(['/forgot-password', '/api/v1/stores/999/subscription/orders']);
