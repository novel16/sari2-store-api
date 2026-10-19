<?php

namespace App\Console\Commands\Billing;

use App\Exceptions\CheckoutConflict;
use App\Models\Billing\PayMongoWebhookEvent;
use App\Models\Billing\SubscriptionOrder;
use App\Services\Billing\ExpireSubscriptionOrder;
use App\Services\Billing\FulfillSubscriptionOrder;
use App\Services\Billing\ProcessPayMongoWebhook;
use App\Services\Billing\RecoverSubscriptionCheckout;
use App\Services\Payments\PayMongo\PayMongoConfiguration;
use App\Services\Payments\PayMongo\PayMongoConfigurationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ReconcileSubscriptionPayments extends Command
{
    protected $signature = 'billing:reconcile {--order= : Restrict to an order UUID} {--session= : Recover its existing PayMongo checkout ID}';

    protected $description = 'Retry stored verified payment events, pending activations and safe checkout expiration';

    public function handle(ProcessPayMongoWebhook $processor, FulfillSubscriptionOrder $fulfillment,
        ExpireSubscriptionOrder $expiration, RecoverSubscriptionCheckout $recovery, PayMongoConfiguration $configuration): int
    {
        $uuid = $this->option('order');
        $session = $this->option('session');
        if (($uuid !== null && ! Str::isUuid($uuid)) || ($session !== null && $uuid === null)) {
            $this->error('Supply a valid --order UUID when using --session.');

            return self::INVALID;
        }
        $order = $uuid ? SubscriptionOrder::where('uuid', $uuid)->first() : null;
        if ($uuid && ! $order) {
            $this->error('Order not found.');

            return self::FAILURE;
        }
        $failed = false;
        if ($session !== null) {
            try {
                $recovery->attach($order, $session);
            } catch (Throwable $error) {
                $this->safeFailure('checkout_recovery', $order->uuid, $error);

                return self::FAILURE;
            }
        }

        $events = PayMongoWebhookEvent::whereIn('status', ['received', 'failed']);
        if ($order) {
            $events->where('checkout_session_id', $order->fresh()->checkout_session_id);
        }
        // Visit every row present at the start in bounded memory. Repeatedly
        // selecting only the first batch would starve later unresolved orders.
        $eventCeiling = (clone $events)->max('id');
        $events->where('id', '<=', $eventCeiling ?? 0)->chunkById(200, function ($events) use ($processor, &$failed) {
            foreach ($events as $event) {
                try {
                    $processor->handle($event->id);
                } catch (Throwable $error) {
                    $failed = true;
                    $this->safeFailure('event_retry', $event->event_id, $error);
                }
            }
        }, 'id');
        $orders = SubscriptionOrder::where('payment_status', 'paid')->whereNull('subscription_id');
        if ($order) {
            $orders->whereKey($order->id);
        }
        $orderCeiling = (clone $orders)->max('id');
        $orders->where('id', '<=', $orderCeiling ?? 0)->chunkById(200, function ($orders) use ($fulfillment, &$failed) {
            foreach ($orders as $paid) {
                try {
                    $fulfillment->handle($paid);
                } catch (Throwable $error) {
                    $failed = true;
                    $this->safeFailure('activation_retry', $paid->uuid, $error);
                }
            }
        }, 'id');
        try {
            // Disabling new checkouts must still allow existing ones to expire.
            $configuration->ensureApiReady();
            $canExpire = true;
        } catch (PayMongoConfigurationException) {
            $canExpire = false;
        }
        if ($canExpire) {
            $pending = SubscriptionOrder::whereIn('payment_status', ['pending', 'unknown', 'creating'])
                ->whereNotNull('checkout_session_id')->where('expires_at', '<=', now());
            if ($order) {
                $pending->whereKey($order->id);
            }
            $pendingCeiling = (clone $pending)->max('id');
            $pending->where('id', '<=', $pendingCeiling ?? 0)->chunkById(100, function ($orders) use ($expiration, &$failed) {
                foreach ($orders as $unpaid) {
                    try {
                        $expiration->expire($unpaid);
                    } catch (CheckoutConflict) {
                        // A paid/ongoing attempt waits for its signed callback.
                    } catch (Throwable $error) {
                        $failed = true;
                        $this->safeFailure('checkout_expiration', $unpaid->uuid, $error);
                    }
                }
            }, 'id');
        }
        $this->info('Reconciliation finished. Inspect subscription payments and billing.* log entries for unresolved orders.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function safeFailure(string $operation, string $reference, Throwable $error): void
    {
        Log::warning('billing.reconciliation_failed', ['operation' => $operation, 'reference' => $reference, 'error_type' => $error::class]);
        $this->warn($operation.' needs another attempt for '.$reference.'.');
    }
}
