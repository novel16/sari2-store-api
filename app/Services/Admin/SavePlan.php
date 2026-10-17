<?php

namespace App\Services\Admin;

use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Plans\PlanTerms;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SavePlan
{
    public function handle(array $attributes, ?Plan $plan = null, ?int $actorId = null): Plan
    {
        return DB::transaction(function () use ($attributes, $plan, $actorId) {
            if ($plan) {
                $plan = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                abort_unless($plan->version === (int) $attributes['expected_version'], 409,
                    'This plan changed. Reload its latest version before saving.');
                $previous = $plan->versions()->firstOrCreate(['version' => $plan->version], [
                    'terms' => PlanTerms::fromPlan($plan), 'change_note' => 'Terms before catalog update',
                ]);
                if ($plan->code !== 'free') {
                    // One update captures all legacy subscriptions, without a query per subscriber.
                    $plan->subscriptions()->whereNull('plan_version_id')->update(['plan_version_id' => $previous->id]);
                }
            } else {
                $plan = new Plan;
                $plan->version = 0;
            }
            $plan->fill(Arr::except($attributes, ['expected_version', 'change_note']));
            $plan->version++;
            $plan->save();
            PlanVersion::create(['plan_id' => $plan->id, 'version' => $plan->version,
                'terms' => PlanTerms::fromPlan($plan->refresh()), 'actor_user_id' => $actorId,
                'change_note' => $attributes['change_note'] ?? null]);

            return $plan->loadCount('subscriptions');
        }, 3);
    }
}
