<?php

namespace App\Services\Admin\Subscriptions;

use App\Models\Account;
use App\Models\Plan;
use App\Services\Plans\PlanTerms;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SubscriptionProposal
{
    public function build(Account $account, Plan $plan, int $months, CarbonImmutable $at): array
    {
        $account->load('owner');
        $this->require($account->status === 'active' && $account->owner->status === 'active', 'Only an active account with an active owner can receive a paid period.');
        $this->require($plan->code === 'basic' && $plan->catalog_status === 'published'
            && $plan->monthly_price_centavos > 0 && $plan->store_limit === 1 && $plan->staff_limit === 0
            && empty($plan->planned_features), 'Only the published Basic plan with supported features can be activated.');
        $this->require($account->stores()->count() <= 1
            && ! $account->memberships()->where('status', 'active')->where('role', '!=', 'owner')->exists(),
            'Basic supports one store and owner access only. Review this account before activation.');

        $paid = $account->subscriptions()->where('status', 'active')
            ->whereHas('plan', fn ($query) => $query->where('code', '!=', 'free'))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
        $this->require(! (clone $paid)->where(fn ($q) => $q->whereNull('ends_at')
            ->orWhere('plan_id', '!=', $plan->id))->exists(),
            'This account has an incompatible or open-ended paid period. Resolve it before activating Basic.');
        $lastEnd = (clone $paid)->max('ends_at');
        $starts = $lastEnd ? CarbonImmutable::parse($lastEnd) : $at;
        $version = $plan->versions()->where('version', $plan->version)->first();
        $this->require($version !== null, 'Save a version of Basic before activating it.');

        return ['account_id' => $account->id, 'account_name' => $account->name,
            'action' => $lastEnd ? 'renew' : 'activate', 'plan_id' => $plan->id,
            'plan_version_id' => $version->id, 'plan_version' => $plan->version,
            'terms' => PlanTerms::fromPlan($plan), 'months' => $months,
            'total_centavos' => $plan->monthly_price_centavos * $months,
            'starts_at' => $starts->toIso8601String(),
            // Calendar months follow the store's Philippine date; persist instants in UTC.
            'ends_at' => $starts->setTimezone('Asia/Manila')->addMonthsNoOverflow($months)->utc()->toIso8601String(),
            'latest_subscription_id' => $account->subscriptions()->max('id')];
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['subscription' => [$message]]);
        }
    }
}
