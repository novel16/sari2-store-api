<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionActivationRequest;
use App\Http\Requests\Admin\SubscriptionPreviewRequest;
use App\Http\Resources\Admin\SubscriptionActivationResource;
use App\Http\Resources\PlanResource;
use App\Models\Account;
use App\Models\Admin\SubscriptionActivation;
use App\Models\Plan;
use App\Services\Admin\Subscriptions\ActivateSubscription;
use App\Services\Admin\Subscriptions\SubscriptionQuote;
use App\Services\Plans\EffectivePlan;
use App\Services\Plans\SubscriptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountSubscriptionController extends Controller
{
    public function show(Account $account, EffectivePlan $plans, SubscriptionStatus $status): JsonResponse
    {
        $effective = $plans->forAccount($account);

        return response()->json(['data' => [
            'account' => $account->only(['id', 'name', 'status']),
            'current' => ['plan' => $effective['terms'], 'starts_at' => $effective['subscription']?->starts_at,
                'ends_at' => $effective['subscription']?->ends_at],
            'renewal' => $status->forAccount($account),
            'available_plan' => ($basic = Plan::where('code', 'basic')->where('catalog_status', 'published')->first()) ? new PlanResource($basic) : null,
        ]]);
    }

    public function history(Account $account): AnonymousResourceCollection
    {
        return SubscriptionActivationResource::collection(SubscriptionActivation::where('account_id', $account->id)
            ->orderByDesc('id')->paginate(20));
    }

    public function preview(SubscriptionPreviewRequest $request, Account $account, SubscriptionQuote $quotes): JsonResponse
    {
        return response()->json(['data' => $quotes->create($account, $request->user(), $request->validated())]);
    }

    public function store(SubscriptionActivationRequest $request, Account $account, ActivateSubscription $activate): JsonResponse
    {
        $result = $activate->handle($account, $request->user(), $request->validated());

        return response()->json(['data' => new SubscriptionActivationResource($result['activation']),
            'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }
}
