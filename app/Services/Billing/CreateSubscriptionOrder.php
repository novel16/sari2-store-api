<?php

namespace App\Services\Billing;

use App\Exceptions\CheckoutConflict;
use App\Models\Billing\SubscriptionOrder;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\PayMongo\PayMongoClient;
use App\Services\Payments\PayMongo\PayMongoConfiguration;
use App\Services\Payments\PayMongo\PayMongoPayloadException;
use App\Services\Payments\PayMongo\PayMongoRequestException;
use App\Services\Plans\PlanTerms;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateSubscriptionOrder
{
    public function __construct(private PayMongoConfiguration $configuration, private PayMongoClient $gateway,
        private SubscriptionPurchasePolicy $policy) {}

    public function handle(Store $store, User $actor, array $input): array
    {
        $hash = hash('sha256', json_encode([$actor->id, $store->id, $store->account_id,
            (int) $input['plan_id'], (int) $input['expected_plan_version'], (int) $input['months']]));
        $result = DB::transaction(function () use ($store, $actor, $input, $hash) {
            $store = StoreWriteAccess::lock($store);
            $account = $store->account;
            $owner = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($account->owner_user_id === $owner->id && $owner->status === 'active' && ! $owner->isPlatformAdmin(), 403);
            $account->setRelation('owner', $owner);

            $previous = SubscriptionOrder::where('request_key', $input['request_key'])->first();
            if ($previous) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    throw new CheckoutConflict('subscription_order_key_conflict', 'This request key belongs to a different purchase. Check the existing payment before trying again.');
                }

                return ['order' => $previous, 'replayed' => true];
            }
            $this->configuration->ensureReady();
            if (SubscriptionOrder::where('account_id', $account->id)->where(fn ($query) => $query->whereNotNull('active_account_id')->orWhere(fn ($paid) => $paid->where('payment_status', 'paid')->whereNull('subscription_id')))->exists()) {
                throw new CheckoutConflict('subscription_checkout_pending', 'This account already has a payment being resolved. Refresh the subscription page and resume or check that payment.');
            }
            $plan = Plan::whereKey($input['plan_id'])->lockForUpdate()->firstOrFail();
            if ($plan->version !== (int) $input['expected_plan_version']) {
                throw new CheckoutConflict('subscription_offer_changed', 'This offer changed. Refresh and review its current price before paying.');
            }
            $this->policy->offer($account, $plan);
            $version = $plan->versions()->where('version', $plan->version)->firstOrFail();
            $order = SubscriptionOrder::create([
                'uuid' => (string) Str::uuid(), 'account_id' => $account->id, 'active_account_id' => $account->id,
                'owner_user_id' => $owner->id, 'owner_name_snapshot' => $owner->name, 'account_name_snapshot' => $account->name,
                'store_id' => $store->id, 'plan_id' => $plan->id, 'plan_version_id' => $version->id, 'plan_version' => $plan->version,
                'terms' => PlanTerms::fromPlan($plan), 'months' => (int) $input['months'],
                'amount_centavos' => $plan->monthly_price_centavos * (int) $input['months'], 'currency' => 'PHP',
                'livemode' => $this->configuration->liveMode(), 'payment_status' => 'creating', 'fulfillment_status' => 'pending',
                'request_key' => $input['request_key'], 'request_hash' => $hash,
                'expires_at' => now()->addMinutes(max(1, (int) config('paymongo.checkout_lifetime_minutes', 30))),
            ]);

            return ['order' => $order, 'replayed' => false];
        });
        $order = $result['order'];
        if ($result['replayed']) {
            return $result;
        }

        // Commit our identity and unresolved-account slot BEFORE a network call.
        // An ambiguous result is retained; replaying the local key never creates another session.
        $base = rtrim(config('app.frontend_url'), '/').'/subscription?order='.$order->uuid;
        try {
            $checkout = $this->gateway->createCheckout($order->uuid,
                mb_substr($order->terms['name'].' — '.$order->months.' month subscription', 0, 200),
                $order->amount_centavos, $base.'&payment=return', $base.'&payment=back');
            DB::transaction(function () use ($order, $checkout) {
                DB::table('accounts')->where('id', $order->account_id)->lockForUpdate()->first();
                $current = SubscriptionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($current->checkout_session_id !== null && $current->checkout_session_id !== $checkout['id']) {
                    throw new PayMongoPayloadException('checkout_order_mismatch');
                }
                $current->checkout_session_id = $checkout['id'];
                $current->checkout_url = $checkout['checkout_url'];
                // A fast webhook may have already settled and fulfilled this order.
                if (in_array($current->payment_status, ['creating', 'unknown'], true)) {
                    $current->payment_status = 'pending';
                    $current->issue_code = null;
                }
                $current->save();
            });
        } catch (PayMongoRequestException|PayMongoPayloadException $error) {
            $definitive = $error instanceof PayMongoRequestException && in_array($error->status, [400, 401, 403, 404, 422], true);
            SubscriptionOrder::whereKey($order->id)->where('payment_status', 'creating')->update([
                'payment_status' => $definitive ? 'failed' : 'unknown',
                'active_account_id' => $definitive ? null : $order->account_id,
                'issue_code' => $definitive ? 'provider_request_rejected' : 'checkout_creation_unknown',
            ]);
            Log::warning('billing.checkout_creation_unconfirmed', ['order' => $order->uuid,
                ...($error instanceof PayMongoRequestException ? $error->context() : ['reason' => $error->reason])]);
        }

        return ['order' => $order->fresh(), 'replayed' => false];
    }
}
