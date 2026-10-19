<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

trait PayMongoPaymentFixtures
{
    protected function paidCheckoutEvent(string $reference, string $checkoutId, array $overrides = []): array
    {
        return array_replace_recursive([
            'data' => [
                'id' => 'evt_paid001',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => $checkoutId,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'reference_number' => $reference,
                            'metadata' => ['order_id' => $reference],
                            'livemode' => false,
                            'payments' => [[
                                'id' => 'pay_paid001',
                                'type' => 'payment',
                                'attributes' => [
                                    'amount' => 4900,
                                    'currency' => 'PHP',
                                    'status' => 'paid',
                                    'livemode' => false,
                                    'source' => ['type' => 'qrph'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    protected function signedPayMongoEvent(array $event, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        return $this->rawPayMongoEvent(json_encode($event, JSON_THROW_ON_ERROR), $timestamp, $secret);
    }

    protected function rawPayMongoEvent(string $body, ?int $timestamp = null, ?string $secret = null, ?string $signature = null): TestResponse
    {
        $timestamp ??= now()->timestamp;
        $secret ??= config('paymongo.webhook_secret');
        $signature ??= 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$body, $secret).',li=';

        // Send the exact signed bytes: postJson would re-encode the payload and hide raw-body regressions.
        return $this->call('POST', '/api/v1/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $body);
    }
}
