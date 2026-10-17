<?php

namespace App\Services\Plans;

use App\Models\Account;
use App\Models\Subscription;

class SubscriptionStatus
{
    public function forAccount(Account $account): array
    {
        $scheduled = $account->subscriptions()->where('status', 'active')->where('starts_at', '>', now())
            ->whereHas('plan', fn ($query) => $query->where('code', '!=', 'free'));
        $next = (clone $scheduled)->with(['plan', 'planVersion'])->orderBy('starts_at')->orderBy('id')->first();

        return [
            'mode' => 'manual', 'auto_renew' => false,
            'next_period' => $next ? $this->period($next) : null,
            'scheduled_periods' => (clone $scheduled)->count(),
            'last_paid_ended_at' => $account->subscriptions()->whereHas('plan', fn ($q) => $q->where('code', '!=', 'free'))
                ->where('ends_at', '<=', now())->orderByDesc('ends_at')->first()?->ends_at,
        ];
    }

    public function period(Subscription $subscription): array
    {
        return ['id' => $subscription->id, 'plan' => PlanTerms::fromSubscription($subscription),
            'starts_at' => $subscription->starts_at, 'ends_at' => $subscription->ends_at];
    }
}
