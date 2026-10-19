<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SubscriptionPurchasePolicy
{
    public function offer(Account $account, Plan $plan): void
    {
        if ($plan->code !== 'basic' || $plan->catalog_status !== 'published' || $plan->monthly_price_centavos < 100
            || $plan->store_limit !== 1 || $plan->staff_limit !== 0 || ! empty($plan->planned_features)) {
            throw ValidationException::withMessages(['plan_id' => ['Only the published Basic offer with supported features can be purchased.']]);
        }
        if (($issue = $this->accountIssue($account)) !== null) {
            throw ValidationException::withMessages(['subscription' => ['This account is not eligible for checkout. Resolve its access or plan compatibility first.']]);
        }
        $this->coverageEnd($account, $plan->id, CarbonImmutable::now('UTC'));
    }

    public function accountIssue(Account $account, ?int $ownerId = null, ?int $storeId = null): ?string
    {
        $account->loadMissing('owner');
        if ($account->status !== 'active' || $account->owner->status !== 'active') {
            return 'account_unavailable';
        }
        if ($ownerId !== null && $account->owner_user_id !== $ownerId) {
            return 'owner_changed';
        }
        if ($account->stores()->count() !== 1 || $account->memberships()->where('status', 'active')->where('role', '!=', 'owner')->exists()) {
            return 'account_incompatible';
        }
        if ($storeId !== null && ! $account->stores()->whereKey($storeId)->where('status', 'active')->exists()) {
            return 'store_unavailable';
        }

        return null;
    }

    public function coverageEnd(Account $account, int $planId, CarbonImmutable $at): CarbonImmutable
    {
        $periods = $account->subscriptions()->where('status', 'active')->whereHas('plan', fn ($query) => $query->where('code', '!=', 'free'))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderBy('starts_at')->orderBy('id')->get(['id', 'plan_id', 'starts_at', 'ends_at']);
        $end = $at;
        foreach ($periods as $period) {
            if ($period->plan_id !== $planId || $period->ends_at === null || $period->starts_at->gt($end)
                || $period->ends_at->lte($period->starts_at)) {
                throw ValidationException::withMessages(['subscription' => ['Existing paid coverage is incompatible, open-ended, or has a gap. Contact support before purchasing.']]);
            }
            $end = $end->max(CarbonImmutable::instance($period->ends_at));
        }

        return $end;
    }
}
