<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\Store;
use App\Services\Catalog\ProductQuota;
use App\Services\Plans\EffectivePlan;
use App\Services\Plans\SubscriptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function show(Store $store, EffectivePlan $plans, ProductQuota $quota, SubscriptionStatus $status): JsonResponse
    {
        return DB::transaction(function () use ($store, $plans, $quota, $status) {
            $store->loadMissing('account');
            $effective = $plans->forAccount($store->account);
            $subscription = $effective['subscription'];

            return response()->json(['data' => [
                'account_id' => $store->account_id,
                'current' => ['plan' => $effective['terms'], 'status' => $subscription ? 'active' : 'free_fallback',
                    'subscription_id' => $subscription?->id, 'starts_at' => $subscription?->starts_at,
                    'ends_at' => $subscription?->ends_at, 'saved_terms' => $subscription?->plan_version_id !== null && $effective['terms']['code'] !== 'free'],
                'usage' => [...$quota->usage($store, $effective['terms']), 'stores' => $store->account->stores()->count(),
                    'staff' => $store->account->memberships()->where('status', 'active')->where('role', '!=', 'owner')->count()],
                'plans' => PlanResource::collection(Plan::whereIn('catalog_status', ['published', 'coming_soon'])->orderBy('sort_order')->orderBy('id')->get()),
                'billing_available' => false,
                'manual_activation_available' => true,
                'renewal' => $status->forAccount($store->account),
            ]]);
        });
    }
}
