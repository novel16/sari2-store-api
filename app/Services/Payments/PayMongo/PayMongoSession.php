<?php

namespace App\Services\Payments\PayMongo;

class PayMongoSession
{
    public function normalize(array $resource): array
    {
        $attributes = $resource['attributes'] ?? null;

        if (! $this->validId($resource['id'] ?? null, 'cs_')
            || ($resource['type'] ?? null) !== 'checkout_session'
            || ! is_array($attributes) || ! is_bool($attributes['livemode'] ?? null)) {
            throw new PayMongoPayloadException('invalid_checkout_session');
        }

        // Keep only fields used by payment verification. Billing data and client keys stay out.
        $safe = array_intersect_key($attributes, array_flip([
            'livemode', 'status', 'reference_number', 'checkout_url',
        ]));

        if (array_key_exists('checkout_url', $safe) && $safe['checkout_url'] !== null) {
            $this->validateCheckoutUrl($safe['checkout_url']);
        }

        $metadata = $attributes['metadata'] ?? [];
        $safe['metadata'] = is_array($metadata) ? array_intersect_key($metadata, ['order_id' => true]) : [];
        $payments = $attributes['payments'] ?? [];

        if (! is_array($payments) || ! array_is_list($payments)) {
            throw new PayMongoPayloadException('invalid_payment_list');
        }

        $safe['payments'] = array_map(function ($payment) {
            if (! is_array($payment) || ! is_array($payment['attributes'] ?? null)) {
                throw new PayMongoPayloadException('invalid_payment');
            }

            $attributes = array_intersect_key($payment['attributes'], array_flip([
                'amount', 'currency', 'status', 'livemode', 'paid_at',
            ]));
            $source = $payment['attributes']['source'] ?? [];
            $attributes['source'] = is_array($source) ? array_intersect_key($source, ['type' => true]) : [];

            return ['id' => $payment['id'] ?? null, 'attributes' => $attributes];
        }, $payments);

        return ['id' => $resource['id'], 'type' => 'checkout_session', 'attributes' => $safe];
    }

    public function validateCheckoutUrl(mixed $url): void
    {
        $parts = is_string($url) && strlen($url) <= 4096 ? parse_url($url) : false;

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'checkout.paymongo.com'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f]/', $url)
            || ! str_starts_with($parts['path'] ?? '', '/')
            || str_starts_with($parts['path'] ?? '', '//')
            || strlen($parts['path'] ?? '') < 2) {
            throw new PayMongoPayloadException('invalid_checkout_url');
        }
    }

    public function validId(mixed $id, string $prefix): bool
    {
        return is_string($id) && strlen($id) <= 100
            && preg_match('/^'.preg_quote($prefix, '/').'[A-Za-z0-9]+$/D', $id) === 1;
    }
}
