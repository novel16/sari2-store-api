<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Billing\PayMongoWebhookEvent;
use App\Models\Billing\SubscriptionOrder;
use App\Services\Payments\PayMongo\PayMongoPaidCheckout;
use App\Services\Payments\PayMongo\PayMongoPayloadException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPayMongoWebhook
{
    public function __construct(private PayMongoPaidCheckout $payments, private FulfillSubscriptionOrder $fulfillment) {}

    public function handle(int $eventId): void
    {
        $event = PayMongoWebhookEvent::findOrFail($eventId);
        if (in_array($event->status, ['ignored', 'rejected'], true)) {
            return;
        }
        $reference = $event->payload['attributes']['reference_number'] ?? null;
        $order = is_string($reference) ? SubscriptionOrder::where('uuid', $reference)->first() : null;
        if (! $order) {
            $event->update(['status' => 'ignored', 'error_code' => 'order_not_found', 'processed_at' => now()]);

            return;
        }
        try {
            DB::transaction(function () use ($eventId, $order) {
                Account::whereKey($order->account_id)->lockForUpdate()->firstOrFail();
                $order = SubscriptionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $event = PayMongoWebhookEvent::whereKey($eventId)->lockForUpdate()->firstOrFail();
                if ($event->status === 'processed') {
                    return;
                }
                $paid = $this->payments->validate($event->payload, $order->uuid, $order->amount_centavos,
                    $order->livemode, $order->checkout_session_id);
                if ($order->payment_id !== null && $order->payment_id !== $paid['payment_id']) {
                    throw new PayMongoPayloadException('order_payment_mismatch');
                }
                // A delayed paid callback may arrive after an expired order was
                // replaced. Record that payment without taking the newer slot.
                $mayHoldSlot = $order->subscription_id === null
                    && ! SubscriptionOrder::where('active_account_id', $order->account_id)
                        ->where('id', '!=', $order->id)->exists();
                // Only a signature-verified durable webhook can enter this path.
                // Return URLs and owner status requests never set payment_status.
                $order->update(['payment_status' => 'paid', 'payment_id' => $paid['payment_id'],
                    'checkout_session_id' => $event->checkout_session_id,
                    'paid_at' => $order->paid_at ?? ($paid['paid_at'] ? CarbonImmutable::createFromTimestampUTC($paid['paid_at']) : now()),
                    'active_account_id' => $mayHoldSlot ? $order->account_id : null,
                    'issue_code' => $order->subscription_id === null ? null : $order->issue_code]);
                $event->update(['status' => 'processed', 'attempts' => $event->attempts + 1,
                    'order_id' => $order->id, 'error_code' => null, 'processed_at' => now()]);
            }, 3);
        } catch (PayMongoPayloadException|UniqueConstraintViolationException $error) {
            $code = $error instanceof PayMongoPayloadException ? $error->reason : 'payment_already_assigned';
            $event->update(['status' => 'rejected', 'error_code' => $code, 'processed_at' => now()]);
            Log::warning('billing.webhook_rejected', ['event' => $event->event_id, 'order' => $order->uuid, 'reason' => $code]);

            return;
        }

        // Keep the verified payment even if provisioning fails. Retrying this
        // separate transaction cannot lose the receipt or grant a second period.
        try {
            $this->fulfillment->handle($order->fresh());
        } catch (Throwable $error) {
            SubscriptionOrder::whereKey($order->id)->whereNull('subscription_id')
                ->update(['fulfillment_status' => 'needs_attention', 'issue_code' => 'activation_failed']);
            Log::error('billing.fulfillment_failed', ['event' => $event->event_id, 'order' => $order->uuid, 'error_type' => $error::class]);
            throw new \RuntimeException('Subscription activation needs a retry.');
        }
    }
}
