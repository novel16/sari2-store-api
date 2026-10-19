<?php

namespace App\Services\Billing;

use App\Exceptions\CheckoutConflict;
use App\Models\Account;
use App\Models\Billing\SubscriptionOrder;
use App\Services\Payments\PayMongo\PayMongoClient;
use App\Services\Payments\PayMongo\PayMongoPayloadException;
use Illuminate\Support\Facades\DB;

class RecoverSubscriptionCheckout
{
    public function __construct(private PayMongoClient $gateway) {}

    public function attach(SubscriptionOrder $order, string $sessionId): void
    {
        // Operator-only recovery for a lost CREATE response. Retrieve the existing
        // session rather than blindly creating a second payable checkout.
        $session = $this->gateway->retrieveCheckout($sessionId);
        $attributes = $session['attributes'];
        if ($session['id'] !== $sessionId
            || ($attributes['reference_number'] ?? null) !== $order->uuid
            || ($attributes['metadata']['order_id'] ?? null) !== $order->uuid
            || $attributes['livemode'] !== $order->livemode) {
            throw new PayMongoPayloadException('checkout_order_mismatch');
        }
        DB::transaction(function () use ($order, $session, $attributes) {
            Account::whereKey($order->account_id)->lockForUpdate()->firstOrFail();
            $current = SubscriptionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($current->checkout_session_id !== null && $current->checkout_session_id !== $session['id']) {
                throw new CheckoutConflict('subscription_checkout_already_linked', 'This order already has a different checkout session.');
            }
            $hasPayment = collect($attributes['payments'])->contains(fn (array $payment) => in_array($payment['attributes']['status'] ?? null, ['paid', 'pending', 'processing'], true));
            $expired = ($attributes['status'] ?? null) === 'expired' && ! $hasPayment;
            $current->checkout_session_id = $session['id'];
            $current->checkout_url = $attributes['checkout_url'] ?? null;
            if (in_array($current->payment_status, ['creating', 'pending', 'unknown'], true)) {
                $current->payment_status = $expired ? 'expired' : 'pending';
                $current->active_account_id = $expired ? null : $current->account_id;
                $current->issue_code = $hasPayment ? 'awaiting_payment_webhook' : null;
            }
            // A retrieved payment is diagnostic evidence. It cannot mark paid;
            // that transition remains exclusive to signature-verified webhooks.
            $current->save();
        });
    }
}
