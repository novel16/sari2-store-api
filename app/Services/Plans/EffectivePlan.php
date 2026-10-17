<?php

namespace App\Services\Plans;

use App\Models\Account;
use App\Models\Plan;

class EffectivePlan
{
    public function forAccount(Account $account): array
    {
        $subscription = $account->currentSubscription()->with(['plan', 'planVersion'])->first();
        if ($subscription) {
            return ['subscription' => $subscription, 'terms' => PlanTerms::fromSubscription($subscription)];
        }
        $free = Plan::where('code', 'free')->firstOrFail();

        return ['subscription' => null, 'terms' => PlanTerms::fromPlan($free)];
    }
}
