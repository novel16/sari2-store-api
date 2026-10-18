<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Http\Resources\Admin\PlanResource;
use App\Http\Resources\Admin\PlanVersionResource;
use App\Models\Plan;
use App\Services\Admin\SavePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::withCount('subscriptions')->orderBy('sort_order')->orderBy('id')->paginate(20));
    }

    public function show(Plan $plan): PlanResource
    {
        return new PlanResource($plan->loadCount('subscriptions'));
    }

    public function store(PlanRequest $request, SavePlan $save): JsonResponse
    {
        return (new PlanResource($save->handle($request->validated(), actorId: $request->user()->id, expectedActor: $request->user())))
            ->response()->setStatusCode(201);
    }

    public function update(PlanRequest $request, Plan $plan, SavePlan $save): PlanResource
    {
        return new PlanResource($save->handle($request->validated(), $plan, $request->user()->id, $request->user()));
    }

    public function versions(Plan $plan): AnonymousResourceCollection
    {
        return PlanVersionResource::collection($plan->versions()->orderByDesc('version')->paginate(20));
    }
}
