<?php

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\Subscription;

class PlanTerms
{
    public static function fromPlan(Plan $plan): array
    {
        return $plan->only(['code', 'name', 'monthly_price_centavos', 'active_product_limit',
            'quota_scope', 'store_limit', 'staff_limit', 'ads_enabled']);
    }

    public static function fromSubscription(Subscription $subscription): array
    {
        // Free allowances follow the Free catalog. Paid subscriptions retain agreed terms.
        return $subscription->plan->code !== 'free' && $subscription->planVersion
            ? $subscription->planVersion->terms : self::fromPlan($subscription->plan);
    }
}
