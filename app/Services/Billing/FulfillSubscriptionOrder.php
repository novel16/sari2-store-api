<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Billing\SubscriptionOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillSubscriptionOrder
{
    public function __construct(private SubscriptionPurchasePolicy $policy) {}

    public function handle(SubscriptionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            // Financial writes and manual renewals share this account-first lock.
            $account = Account::whereKey($order->account_id)->lockForUpdate()->firstOrFail();
            $order = SubscriptionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status !== 'paid' || $order->subscription_id !== null) {
                return;
            }
            $owner = User::whereKey($account->owner_user_id)->lockForUpdate()->firstOrFail();
            $account->setRelation('owner', $owner);
            $issue = $this->policy->accountIssue($account, $order->owner_user_id, $order->store_id);
            try {
                $starts = $issue === null ? $this->policy->coverageEnd($account, $order->plan_id, CarbonImmutable::now('UTC')) : null;
            } catch (ValidationException) {
                $issue = 'coverage_incompatible';
            }
            if ($issue !== null) {
                $order->update(['fulfillment_status' => 'needs_attention', 'issue_code' => $issue]);

                return;
            }

            // The purchased version is immutable. Catalog edits after checkout must
            // not change the price/allowances for a payment we already accepted.
            $ends = $starts->setTimezone('Asia/Manila')->addMonthsNoOverflow($order->months)->utc();
            $subscription = $account->subscriptions()->create([
                'plan_id' => $order->plan_id, 'plan_version_id' => $order->plan_version_id,
                'status' => 'active', 'starts_at' => $starts, 'ends_at' => $ends,
                'provider' => 'paymongo', 'provider_reference' => $order->payment_id,
            ]);
            $order->update(['subscription_id' => $subscription->id, 'fulfillment_status' => 'fulfilled',
                'fulfilled_at' => now(), 'issue_code' => null, 'active_account_id' => null]);
        }, 3);
    }
}
