<?php

namespace App\Services\Payments\PayMongo;

class PayMongoPaidCheckout
{
    public function __construct(private PayMongoSession $sessions) {}

    public function validate(array $session, string $orderUuid, int $amountCentavos, bool $livemode, ?string $sessionId = null): array
    {
        $session = $this->sessions->normalize($session);
        $attributes = $session['attributes'];

        if (($sessionId !== null && $session['id'] !== $sessionId)
            || ($attributes['reference_number'] ?? null) !== $orderUuid
            || ($attributes['metadata']['order_id'] ?? null) !== $orderUuid) {
            throw new PayMongoPayloadException('checkout_order_mismatch');
        }

        if ($attributes['livemode'] !== $livemode) {
            throw new PayMongoPayloadException('checkout_mode_mismatch');
        }

        $paid = array_values(array_filter($attributes['payments'], fn (array $payment) => ($payment['attributes']['status'] ?? null) === 'paid'));

        if (count($paid) !== 1) {
            throw new PayMongoPayloadException('expected_one_paid_payment');
        }

        $payment = $paid[0];
        $details = $payment['attributes'];

        if (! $this->sessions->validId($payment['id'], 'pay_')
            || ! is_int($details['amount'] ?? null) || $details['amount'] !== $amountCentavos || $amountCentavos < 100
            || ($details['currency'] ?? null) !== 'PHP'
            || ($details['source']['type'] ?? null) !== 'qrph'
            || (array_key_exists('livemode', $details) && $details['livemode'] !== $livemode)) {
            throw new PayMongoPayloadException('paid_payment_mismatch');
        }

        $paidAt = $details['paid_at'] ?? null;

        if ($paidAt !== null && (! is_int($paidAt) || $paidAt <= 0)) {
            throw new PayMongoPayloadException('invalid_payment_timestamp');
        }

        return ['payment_id' => $payment['id'], 'amount_centavos' => $details['amount'],
            'currency' => 'PHP', 'livemode' => $livemode, 'paid_at' => $paidAt];
    }
}
