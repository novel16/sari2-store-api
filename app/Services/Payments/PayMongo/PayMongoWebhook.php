<?php

namespace App\Services\Payments\PayMongo;

use JsonException;

class PayMongoWebhook
{
    public function __construct(private PayMongoConfiguration $configuration, private PayMongoSession $sessions) {}

    public function verify(string $rawBody, ?string $signature): array
    {
        $this->configuration->ensureWebhookReady();
        $this->verifySignature($rawBody, $signature);

        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PayMongoPayloadException('invalid_event_json');
        }

        $event = is_array($payload) ? ($payload['data'] ?? null) : null;

        if (! is_array($event)) {
            throw new PayMongoPayloadException('invalid_event');
        }

        if (array_key_exists('id', $event) && ! $this->sessions->validId($event['id'], 'evt_')) {
            throw new PayMongoPayloadException('invalid_event_id');
        }

        // PayMongo's resource envelope and newer Hosted Checkout envelope carry the same event meaning.
        $attributes = ($event['type'] ?? null) === 'event' ? ($event['attributes'] ?? null) : $event;

        if (($event['type'] ?? null) === 'event' && ! isset($event['id'])) {
            throw new PayMongoPayloadException('invalid_event_id');
        }

        if (! is_array($attributes) || ! is_string($attributes['type'] ?? null)
            || ! preg_match('/^[a-z][a-z0-9_.]{0,99}$/D', $attributes['type'])
            || ! is_bool($attributes['livemode'] ?? null)) {
            throw new PayMongoPayloadException('invalid_event');
        }

        if ($attributes['livemode'] !== $this->configuration->liveMode()) {
            throw new PayMongoPayloadException('event_mode_mismatch');
        }

        $session = null;

        if ($attributes['type'] === 'checkout_session.payment.paid') {
            if (! is_array($attributes['data'] ?? null)) {
                throw new PayMongoPayloadException('missing_checkout_session');
            }

            $resource = $attributes['data'];

            // The abbreviated Hosted Checkout envelope may omit the session mode.
            // Only inherit from the already authenticated event, never from browser input.
            if (is_array($resource['attributes'] ?? null) && ! array_key_exists('livemode', $resource['attributes'])) {
                $resource['attributes']['livemode'] = $attributes['livemode'];
            }

            $session = $this->sessions->normalize($resource);

            if ($session['attributes']['livemode'] !== $attributes['livemode']) {
                throw new PayMongoPayloadException('checkout_mode_mismatch');
            }
        }

        // Some Hosted Checkout deliveries omit an event ID. This signed-byte fingerprint
        // deduplicates identical deliveries; the payment ID remains the settlement guard.
        return ['id' => $event['id'] ?? 'body_'.hash('sha256', $rawBody), 'type' => $attributes['type'],
            'livemode' => $attributes['livemode'], 'session' => $session];
    }

    private function verifySignature(string $rawBody, ?string $signature): void
    {
        if (! is_string($signature) || strlen($signature) > 500) {
            throw new PayMongoSignatureException;
        }

        $parts = [];

        foreach (explode(',', $signature) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2 || ! in_array($pair[0], ['t', 'te', 'li'], true) || array_key_exists($pair[0], $parts)) {
                throw new PayMongoSignatureException;
            }

            $parts[$pair[0]] = $pair[1];
        }

        $timestamp = $parts['t'] ?? '';
        $selected = $this->configuration->liveMode() ? 'li' : 'te';
        $other = $this->configuration->liveMode() ? 'te' : 'li';

        if (! preg_match('/^[1-9][0-9]{0,10}$/D', $timestamp)
            || ! preg_match('/^[a-f0-9]{64}$/D', $parts[$selected] ?? '')
            || (isset($parts[$other]) && $parts[$other] !== '')
            || abs(now()->timestamp - (int) $timestamp) > max(1, (int) config('paymongo.signature_tolerance', 300))) {
            throw new PayMongoSignatureException;
        }

        // Sign the original bytes. Re-encoding JSON changes whitespace and invalidates the provider signature.
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, config('paymongo.webhook_secret'));

        if (! hash_equals($expected, $parts[$selected])) {
            throw new PayMongoSignatureException;
        }
    }
}
