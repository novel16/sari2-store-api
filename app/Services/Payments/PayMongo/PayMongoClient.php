<?php

namespace App\Services\Payments\PayMongo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PayMongoClient
{
    public function __construct(private PayMongoConfiguration $configuration, private PayMongoSession $sessions) {}

    public function createCheckout(string $orderUuid, string $name, int $amountCentavos, string $successUrl, string $cancelUrl): array
    {
        $this->configuration->ensureReady();

        if (! Str::isUuid($orderUuid) || $amountCentavos < 100 || trim($name) === '' || mb_strlen($name) > 200) {
            throw new PayMongoPayloadException('invalid_checkout_input');
        }

        $this->validateReturnUrl($successUrl);
        $this->validateReturnUrl($cancelUrl);
        $session = $this->request('create', 'POST', '/v2/checkout_sessions', [
            'data' => ['attributes' => [
                'line_items' => [['name' => $name, 'amount' => $amountCentavos, 'currency' => 'PHP', 'quantity' => 1]],
                'payment_method_types' => ['qrph'],
                'reference_number' => $orderUuid,
                'metadata' => ['order_id' => $orderUuid],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'pass_on_fees' => false,
                'send_email_receipt' => false,
                'show_description' => false,
                'show_line_items' => true,
            ]],
        ]);
        $url = $session['attributes']['checkout_url'] ?? null;
        $this->sessions->validateCheckoutUrl($url);

        return ['id' => $session['id'], 'checkout_url' => $url, 'livemode' => $session['attributes']['livemode']];
    }

    public function retrieveCheckout(string $id): array
    {
        return $this->request('retrieve', 'GET', '/v1/checkout_sessions/'.$this->checkoutId($id));
    }

    public function expireCheckout(string $id): array
    {
        return $this->request('expire', 'POST', '/v1/checkout_sessions/'.$this->checkoutId($id).'/expire');
    }

    private function request(string $operation, string $method, string $path, ?array $body = null): array
    {
        $this->configuration->ensureApiReady();

        try {
            // A timeout may occur after creation succeeded. Automatic POST retries could create two payable sessions.
            $response = $this->http()->send($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            throw new PayMongoRequestException($operation);
        }

        $requestId = $response->header('Request-Id') ?: $response->header('X-Request-Id');
        $requestId = is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $requestId) ? $requestId : null;

        if (! $response->successful()) {
            throw new PayMongoRequestException($operation, $response->status(), $requestId);
        }

        $resource = $response->json('data');

        if (! is_array($resource)) {
            throw new PayMongoRequestException($operation, $response->status(), $requestId);
        }

        $session = $this->sessions->normalize($resource);

        if ($session['attributes']['livemode'] !== $this->configuration->liveMode()) {
            throw new PayMongoPayloadException('checkout_mode_mismatch');
        }

        return $session;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('paymongo.base_url', 'https://api.paymongo.com'))
            ->withBasicAuth(config('paymongo.secret_key'), '')
            ->acceptJson()->asJson()
            ->connectTimeout((int) config('paymongo.connect_timeout', 5))
            ->timeout((int) config('paymongo.timeout', 15))
            ->withoutRedirecting();
    }

    private function checkoutId(string $id): string
    {
        if (! $this->sessions->validId($id, 'cs_')) {
            throw new PayMongoPayloadException('invalid_checkout_id');
        }

        return $id;
    }

    private function validateReturnUrl(string $url): void
    {
        $base = config('app.frontend_url');
        $expected = is_string($base) ? parse_url($base) : false;
        $actual = strlen($url) <= 2048 ? parse_url($url) : false;

        if (! is_array($expected) || ! is_array($actual)
            || ! in_array($actual['scheme'] ?? null, ['https', 'http'], true)
            || ($actual['scheme'] ?? null) !== ($expected['scheme'] ?? null)
            || ($actual['host'] ?? null) !== ($expected['host'] ?? null)
            || ($actual['port'] ?? null) !== ($expected['port'] ?? null)
            || isset($actual['user']) || isset($actual['pass']) || isset($actual['fragment'])
            || str_contains($url, "\r") || str_contains($url, "\n")) {
            throw new PayMongoPayloadException('invalid_return_url');
        }
    }
}
