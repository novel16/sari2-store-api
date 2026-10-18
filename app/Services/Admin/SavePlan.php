<?php

namespace App\Services\Admin;

use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Plans\PlanTerms;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SavePlan
{
    public function handle(array $attributes, ?Plan $plan = null, ?int $actorId = null, ?User $expectedActor = null): Plan
    {
        return DB::transaction(function () use ($attributes, $plan, $actorId, $expectedActor) {
            if ($plan) {
                $plan = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            }
            $actor = null;
            if ($actorId !== null) {
                $actor = $expectedActor ?? User::findOrFail($actorId);
                abort_unless($actor->id === $actorId, 403, 'An active platform administrator is required.');
                $actor = AdministratorAccess::lock($actor);
            }
            if ($plan) {
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
                'actor_name_snapshot' => $actor?->name,
                'change_note' => $attributes['change_note'] ?? null]);

            return $plan->loadCount('subscriptions');
        }, 3);
    }
}
