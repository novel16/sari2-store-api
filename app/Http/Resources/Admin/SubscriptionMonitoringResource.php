<?php

namespace App\Http\Resources\Admin;

use App\Models\Subscription;
use App\Services\Plans\PlanTerms;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionMonitoringResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource['row'];
        $current = $this->resource['current'];
        $next = $this->resource['next'];

        // Explicit metadata allowlist; never serialize provider refs, payments or complete terms.
        return ['account' => ['id' => (int) $row->account_id, 'name' => $row->account_name, 'status' => $row->account_status],
            'owner' => ['id' => (int) $row->owner_id, 'name' => $row->owner_name, 'email' => $row->owner_email, 'status' => $row->owner_status],
            'state' => $row->state, 'is_expiring' => (bool) $row->is_expiring, 'is_paid' => (bool) $row->is_paid,
            'current' => ['subscription_id' => $current?->id, 'plan' => $current ? $this->plan($current) : $this->resource['free_plan'],
                'starts_at' => $current?->starts_at?->toIso8601String(), 'ends_at' => $current?->ends_at?->toIso8601String()],
            'coverage_ends_at' => $this->date($row->coverage_ends_at),
            'next_period' => $next ? ['id' => $next->id, 'plan' => $this->plan($next),
                'starts_at' => $next->starts_at->toIso8601String(), 'ends_at' => $next->ends_at?->toIso8601String()] : null,
            'scheduled_periods' => (int) $row->scheduled_periods, 'last_paid_ended_at' => $this->date($row->last_paid_ended_at)];
    }

    private function plan(Subscription $period): array
    {
        $terms = PlanTerms::fromSubscription($period);

        return ['code' => $terms['code'], 'name' => $terms['name']];
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601String();
    }
}
