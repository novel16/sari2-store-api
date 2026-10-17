<?php

namespace App\Http\Resources;

use App\Services\Plans\PlanTerms;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accounts = $this->resource->accounts()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'owner')
            ->with(['stores', 'currentSubscription.plan', 'currentSubscription.planVersion'])
            ->orderBy('accounts.id')
            ->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'platform_role' => $this->platform_role,
            'status' => $this->status,
            'accounts' => $accounts->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'status' => $account->status,
                'role' => $account->pivot->role,
                'subscription' => $account->currentSubscription ? [
                    'status' => $account->currentSubscription->status,
                    'plan' => PlanTerms::fromSubscription($account->currentSubscription),
                ] : null,
                'stores' => $account->stores->map(fn ($store) => $store->only([
                    'id', 'account_id', 'name', 'slug', 'status', 'phone', 'address',
                ]))->values(),
            ])->values(),
        ];
    }
}
