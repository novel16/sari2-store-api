<?php

namespace App\Services\Billing;

use App\Exceptions\CheckoutConflict;
use App\Models\Billing\SubscriptionOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\PayMongo\PayMongoClient;
use App\Services\Payments\PayMongo\PayMongoPayloadException;
use App\Services\Payments\PayMongo\PayMongoRequestException;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Facades\DB;

class ExpireSubscriptionOrder
{
    public function __construct(private PayMongoClient $gateway) {}

    public function handle(Store $store, User $actor, SubscriptionOrder $order): SubscriptionOrder
    {
        DB::transaction(function () use ($store, $actor, $order) {
            $locked = StoreWriteAccess::lock($store);
            $owner = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->status === 'active' && $locked->account->owner_user_id === $owner->id
                && $order->owner_user_id === $owner->id && $order->account_id === $locked->account_id, 403);
        });

        return $this->expire($order);
    }

    public function expire(SubscriptionOrder $order): SubscriptionOrder
    {
        $order->refresh();
        if (in_array($order->payment_status, ['paid', 'failed', 'expired'], true)) {
            return $order;
        }
        if ($order->checkout_session_id === null) {
            throw new CheckoutConflict('subscription_checkout_unknown', 'Checkout creation is still being resolved. Contact support with the order reference before making another payment.');
        }
        try {
            $session = $this->gateway->expireCheckout($order->checkout_session_id);
        } catch (PayMongoRequestException $error) {
            if ($error->status !== 400) {
                throw $error;
            }
            $session = $this->gateway->retrieveCheckout($order->checkout_session_id);
        }
        $attributes = $session['attributes'];
        if ($session['id'] !== $order->checkout_session_id || ($attributes['reference_number'] ?? null) !== $order->uuid
            || ($attributes['metadata']['order_id'] ?? null) !== $order->uuid || $attributes['livemode'] !== $order->livemode) {
            throw new PayMongoPayloadException('checkout_order_mismatch');
        }

        // A browser Cancel/Back is not cancellation. Release the payable slot
        // only after PayMongo confirms the session can no longer accept money.
        $ongoing = collect($attributes['payments'])->contains(fn (array $payment) => in_array($payment['attributes']['status'] ?? null, ['paid', 'pending', 'processing'], true));
        if (($attributes['status'] ?? null) !== 'expired' || $ongoing) {
            SubscriptionOrder::whereKey($order->id)->whereIn('payment_status', ['creating', 'pending', 'unknown'])
                ->update(['issue_code' => 'awaiting_payment_webhook']);
            throw new CheckoutConflict('subscription_payment_in_progress', 'Payment may be processing or already received. Keep this order and check its confirmation; do not pay again.');
        }

        DB::transaction(function () use ($order) {
            DB::table('accounts')->where('id', $order->account_id)->lockForUpdate()->first();
            $current = SubscriptionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($current->payment_status !== 'paid') {
                $current->update(['payment_status' => 'expired', 'active_account_id' => null, 'issue_code' => null]);
            }
        });

        return $order->fresh();
    }
}
