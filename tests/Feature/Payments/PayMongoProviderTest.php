<?php

use App\Services\Payments\PayMongo\PayMongoClient;
use App\Services\Payments\PayMongo\PayMongoConfiguration;
use App\Services\Payments\PayMongo\PayMongoConfigurationException;
use App\Services\Payments\PayMongo\PayMongoPaidCheckout;
use App\Services\Payments\PayMongo\PayMongoPayloadException;
use App\Services\Payments\PayMongo\PayMongoRequestException;
use App\Services\Payments\PayMongo\PayMongoSession;
use App\Services\Payments\PayMongo\PayMongoSignatureException;
use App\Services\Payments\PayMongo\PayMongoWebhook;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'paymongo.enabled' => true,
        'paymongo.mode' => 'test',
        'paymongo.secret_key' => 'sk_test_publicFixtureOnly',
        'paymongo.webhook_secret' => 'whsk_publicFixtureOnly',
        'paymongo.base_url' => 'https://api.paymongo.com',
        'paymongo.signature_tolerance' => 300,
        'app.frontend_url' => 'http://localhost:3000',
    ]);
    $this->freezeTime();
    Http::preventStrayRequests();
});

function providerOrderUuid(): string
{
    return '3f67f85c-f08e-4f26-bde0-98411f1fa1f0';
}

function providerSessionFixture(): array
{
    return ['id' => 'cs_Fixture123', 'type' => 'checkout_session', 'attributes' => [
        'livemode' => false,
        'status' => 'active',
        'checkout_url' => 'https://checkout.paymongo.com/cs_Fixture123#public-fixture-fragment',
        'reference_number' => providerOrderUuid(),
        'metadata' => ['order_id' => providerOrderUuid(), 'private' => 'do-not-keep'],
        'billing' => ['email' => 'private@example.test'],
        'client_key' => 'do-not-keep',
        'payments' => [['id' => 'pay_Fixture123', 'type' => 'payment', 'attributes' => [
            'status' => 'paid', 'amount' => 4900, 'currency' => 'PHP', 'livemode' => false,
            'paid_at' => now()->timestamp, 'source' => ['type' => 'qrph', 'id' => 'qr_fixture'],
            'billing' => ['name' => 'Private customer'], 'fee' => 100,
        ]]],
    ]];
}

function providerEventFixture(): array
{
    return ['data' => ['id' => 'evt_Fixture123', 'type' => 'event', 'attributes' => [
        'type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => providerSessionFixture(),
    ]]];
}

function providerSignatureFixture(string $body, ?int $timestamp = null, bool $live = false): string
{
    $timestamp ??= now()->timestamp;
    $hash = hash_hmac('sha256', $timestamp.'.'.$body, 'whsk_publicFixtureOnly');

    return 't='.$timestamp.($live ? ',te=,li='.$hash : ',te='.$hash.',li=');
}

it('requires matching secret mode and a webhook secret before checkout is available', function (array $overrides) {
    config($overrides);
    expect(app(PayMongoConfiguration::class)->ready())->toBeFalse();
    expect(fn () => app(PayMongoConfiguration::class)->ensureReady())->toThrow(PayMongoConfigurationException::class);
    Http::assertNothingSent();
})->with([
    'disabled' => [['paymongo.enabled' => false]],
    'empty key' => [['paymongo.secret_key' => null]],
    'public key rejected' => [['paymongo.secret_key' => 'pk_test_fixture']],
    'wrong key mode' => [['paymongo.secret_key' => 'sk_live_fixture']],
    'invalid mode' => [['paymongo.mode' => 'staging']],
    'empty webhook secret' => [['paymongo.webhook_secret' => ' ']],
]);

it('creates one QR Ph checkout using integer centavos and trusted server return URLs', function () {
    $resource = providerSessionFixture();
    $resource['attributes']['payments'] = [];
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $resource])]);

    $result = app(PayMongoClient::class)->createCheckout(providerOrderUuid(), 'Basic - 1 month', 4900,
        'http://localhost:3000/subscription?checkout='.providerOrderUuid(), 'http://localhost:3000/subscription');

    expect($result)->toBe(['id' => 'cs_Fixture123', 'checkout_url' => $resource['attributes']['checkout_url'], 'livemode' => false]);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $attributes = $request['data']['attributes'];

        return $request->method() === 'POST'
            && $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_publicFixtureOnly:'))
            && $attributes['line_items'] === [['name' => 'Basic - 1 month', 'amount' => 4900, 'currency' => 'PHP', 'quantity' => 1]]
            && $attributes['payment_method_types'] === ['qrph']
            && $attributes['reference_number'] === providerOrderUuid()
            && $attributes['metadata'] === ['order_id' => providerOrderUuid()]
            && $attributes['pass_on_fees'] === false;
    });
});

it('rejects an untrusted checkout return destination before sending any HTTP request', function (string $url) {
    expect(fn () => app(PayMongoClient::class)->createCheckout(providerOrderUuid(), 'Basic', 4900, $url, 'http://localhost:3000/subscription'))
        ->toThrow(PayMongoPayloadException::class, 'invalid_return_url');
    Http::assertNothingSent();
})->with([
    'https://attacker.example/success', 'http://localhost:3001/subscription',
    'http://localhost:3000.attacker.example/subscription', 'http://user@localhost:3000/subscription',
    'http://localhost:3000/subscription#client-control', "http://localhost:3000/subscription\r\nInjected:true",
]);

it('retrieves and explicitly expires the same checkout through v1 without leaking the provider payload', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => providerSessionFixture()])]);
    $client = app(PayMongoClient::class);
    $retrieved = $client->retrieveCheckout('cs_Fixture123');
    $client->expireCheckout('cs_Fixture123');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_Fixture123');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_Fixture123/expire');
    expect(json_encode($retrieved))->not->toContain('private@example.test', 'Private customer', 'client_key', 'do-not-keep', 'qr_fixture');
    expect($retrieved['attributes']['payments'][0]['attributes']['source'])->toBe(['type' => 'qrph']);
});

it('does not follow redirects or retry a failed creation and exposes only sanitized diagnostics', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['errors' => ['detail' => 'PRIVATE sk_test_secret response client_key']], 500, ['Request-Id' => 'req_Safe123'])]);

    try {
        app(PayMongoClient::class)->createCheckout(providerOrderUuid(), 'Basic', 4900, 'http://localhost:3000/subscription', 'http://localhost:3000/subscription');
        $this->fail('Expected a sanitized provider exception.');
    } catch (PayMongoRequestException $error) {
        expect($error->context())->toBe(['operation' => 'create', 'status' => 500, 'request_id' => 'req_Safe123']);
        expect($error->getMessage())->not->toContain('PRIVATE', 'sk_test_', 'client_key');
        expect($error->getPrevious())->toBeNull();
    }

    Http::assertSentCount(1);
});

it('does not retry a connection failure or attach a sensitive underlying HTTP exception', function () {
    Http::fake(['api.paymongo.com/*' => Http::failedConnection('PRIVATE Authorization sk_test_secret')]);

    try {
        app(PayMongoClient::class)->createCheckout(providerOrderUuid(), 'Basic', 4900, 'http://localhost:3000/subscription', 'http://localhost:3000/subscription');
        $this->fail('Expected a sanitized connection exception.');
    } catch (PayMongoRequestException $error) {
        expect($error->status)->toBeNull();
        expect($error->getPrevious())->toBeNull();
        expect($error->getMessage())->not->toContain('PRIVATE', 'Authorization', 'sk_test_');
    }
});

it('rejects unsafe provider checkout URLs', function (string $url) {
    expect(fn () => app(PayMongoSession::class)->validateCheckoutUrl($url))->toThrow(PayMongoPayloadException::class);
})->with([
    'http://checkout.paymongo.com/cs_Fixture123', 'https://checkout.paymongo.com.attacker.example/cs_Fixture123',
    'https://attacker.example/cs_Fixture123', 'javascript:alert(1)',
    'https://checkout.paymongo.com@attacker.example/cs_Fixture123',
    'https://user@checkout.paymongo.com/cs_Fixture123', 'https://checkout.paymongo.com:444/cs_Fixture123',
    'https://checkout.paymongo.com/', 'https://checkout.paymongo.com',
    "https://checkout.paymongo.com/opaque\r\nInjected:true", "https://checkout.paymongo.com/opaq\tue",
]);

it('verifies a raw signed canonical event and removes private provider fields', function () {
    $body = json_encode(providerEventFixture(), JSON_PRETTY_PRINT);
    $event = app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body));

    expect($event['id'])->toBe('evt_Fixture123');
    expect($event['type'])->toBe('checkout_session.payment.paid');
    expect($event['session']['id'])->toBe('cs_Fixture123');
    expect(json_encode($event))->not->toContain('private@example.test', 'client_key', 'do-not-keep');
    expect(fn () => app(PayMongoWebhook::class)->verify(json_encode(providerEventFixture()), providerSignatureFixture($body)))
        ->toThrow(PayMongoSignatureException::class);
});

it('rejects invalid missing ambiguous old and wrong-mode signatures', function (string $kind) {
    $body = json_encode(providerEventFixture());
    $signature = providerSignatureFixture($body);
    $signature = match ($kind) {
        'missing' => null,
        'invalid digest' => 't='.now()->timestamp.',te='.str_repeat('a', 64).',li=',
        'duplicate timestamp' => $signature.',t='.now()->timestamp,
        'duplicate test signature' => $signature.',te='.str_repeat('a', 64),
        'unknown field' => $signature.',extra=1',
        'stale' => providerSignatureFixture($body, now()->timestamp - 301),
        'future' => providerSignatureFixture($body, now()->timestamp + 301),
        'live mode signature' => providerSignatureFixture($body, null, true),
        'both signatures' => str_replace('li=', 'li='.str_repeat('b', 64), $signature),
        'timestamp leading zero' => str_replace('t=', 't=0', $signature),
    };

    expect(fn () => app(PayMongoWebhook::class)->verify($body, $signature))->toThrow(PayMongoSignatureException::class);
})->with(['missing', 'invalid digest', 'duplicate timestamp', 'duplicate test signature', 'unknown field', 'stale', 'future', 'live mode signature', 'both signatures', 'timestamp leading zero']);

it('accepts the replay tolerance boundary but still checks the signed payload mode', function () {
    $body = json_encode(providerEventFixture());
    expect(app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body, now()->timestamp - 300))['id'])->toBe('evt_Fixture123');
    $event = providerEventFixture();
    $event['data']['attributes']['livemode'] = true;
    $body = json_encode($event);
    expect(fn () => app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body)))->toThrow(PayMongoPayloadException::class, 'event_mode_mismatch');
});

it('supports live signature verification using the live signature slot', function () {
    config(['paymongo.mode' => 'live', 'paymongo.secret_key' => 'sk_live_publicFixtureOnly']);
    $event = providerEventFixture();
    $event['data']['attributes']['livemode'] = true;
    $event['data']['attributes']['data']['attributes']['livemode'] = true;
    $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['livemode'] = true;
    $body = json_encode($event);
    expect(app(PayMongoConfiguration::class)->ready())->toBeTrue();
    expect(app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body, null, true))['livemode'])->toBeTrue();
});

it('verifies the documented flattened checkout envelope and fingerprints deliveries without an event ID', function () {
    $event = ['event_type' => 'send.webhook', 'data' => [
        'type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => providerSessionFixture(),
    ]];
    $body = json_encode($event);
    $verified = app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body));
    expect($verified['id'])->toBe('body_'.hash('sha256', $body));
    expect($verified['session']['id'])->toBe('cs_Fixture123');
});

it('does not turn unrelated signed events into checkout payments', function () {
    $event = providerEventFixture();
    $event['data']['attributes']['type'] = 'payment.failed';
    $event['data']['attributes']['data'] = ['unexpected' => 'private'];
    $body = json_encode($event);
    expect(app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body)))->toBe([
        'id' => 'evt_Fixture123', 'type' => 'payment.failed', 'livemode' => false, 'session' => null,
    ]);
});

it('inherits an omitted checkout mode only from the verified webhook event', function () {
    $resource = providerSessionFixture();
    unset($resource['attributes']['livemode']);
    $event = ['event_type' => 'send.webhook', 'data' => [
        'type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => $resource,
    ]];
    $body = json_encode($event);
    $verified = app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body));

    expect($verified['session']['attributes']['livemode'])->toBeFalse();
    expect(app(PayMongoPaidCheckout::class)->validate($verified['session'], providerOrderUuid(), 4900, false)['payment_id'])->toBe('pay_Fixture123');

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $resource])]);
    expect(fn () => app(PayMongoClient::class)->retrieveCheckout('cs_Fixture123'))
        ->toThrow(PayMongoPayloadException::class, 'invalid_checkout_session');
});

it('accepts only the single exact PHP QR Ph payment for the expected order', function () {
    $session = providerSessionFixture();
    array_unshift($session['attributes']['payments'], ['id' => 'pay_FailedFixture', 'attributes' => ['status' => 'failed']]);
    $paid = app(PayMongoPaidCheckout::class)->validate($session, providerOrderUuid(), 4900, false, 'cs_Fixture123');
    expect($paid)->toBe(['payment_id' => 'pay_Fixture123', 'amount_centavos' => 4900, 'currency' => 'PHP', 'livemode' => false, 'paid_at' => now()->timestamp]);
});

it('rejects mismatched or ambiguous paid checkout details', function (string $kind) {
    $session = providerSessionFixture();

    match ($kind) {
        'reference' => $session['attributes']['reference_number'] = 'different-order',
        'metadata' => $session['attributes']['metadata']['order_id'] = 'different-order',
        'mode' => $session['attributes']['livemode'] = true,
        'no paid payment' => $session['attributes']['payments'][0]['attributes']['status'] = 'pending',
        'two paid payments' => $session['attributes']['payments'][] = $session['attributes']['payments'][0],
        'amount' => $session['attributes']['payments'][0]['attributes']['amount'] = 4800,
        'amount string' => $session['attributes']['payments'][0]['attributes']['amount'] = '4900',
        'currency' => $session['attributes']['payments'][0]['attributes']['currency'] = 'USD',
        'method' => $session['attributes']['payments'][0]['attributes']['source']['type'] = 'gcash',
        'payment mode' => $session['attributes']['payments'][0]['attributes']['livemode'] = true,
        'payment id' => $session['attributes']['payments'][0]['id'] = '../pay_bad',
        'timestamp' => $session['attributes']['payments'][0]['attributes']['paid_at'] = 'yesterday',
        'session id' => $session['id'] = 'cs_Other123',
    };

    expect(fn () => app(PayMongoPaidCheckout::class)->validate($session, providerOrderUuid(), 4900, false, 'cs_Fixture123'))->toThrow(PayMongoPayloadException::class);
})->with(['reference', 'metadata', 'mode', 'no paid payment', 'two paid payments', 'amount', 'amount string', 'currency', 'method', 'payment mode', 'payment id', 'timestamp', 'session id']);

it('continues verifying already-issued payments when new checkouts are disabled', function () {
    config(['paymongo.enabled' => false]);
    $body = json_encode(providerEventFixture());
    expect(app(PayMongoConfiguration::class)->ready())->toBeFalse();
    expect(app(PayMongoWebhook::class)->verify($body, providerSignatureFixture($body))['id'])->toBe('evt_Fixture123');
});

it('accepts both documented opaque and resource-ID hosted checkout URLs', function (string $url) {
    app(PayMongoSession::class)->validateCheckoutUrl($url);
    $resource = providerSessionFixture();
    $resource['attributes']['checkout_url'] = $url;
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $resource])]);
    expect(app(PayMongoClient::class)->createCheckout(providerOrderUuid(), 'Basic', 4900,
        'http://localhost:3000/subscription', 'http://localhost:3000/subscription')['checkout_url'])->toBe($url);
})->with([
    'https://checkout.paymongo.com/rT3jQcyHGJaJstWp6nLVB',
    'https://checkout.paymongo.com/rT3jQcyHGJaJstWp6nLVB?locale=en#pk_test_fixture=',
    'https://checkout.paymongo.com/cs_Fixture123#public-fixture-fragment',
    'https://checkout.paymongo.com:443/cs_Fixture123?checkout=1#public-fixture=',
]);
