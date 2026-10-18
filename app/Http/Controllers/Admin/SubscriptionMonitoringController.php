<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionMonitoringRequest;
use App\Http\Resources\Admin\SubscriptionMonitoringResource;
use App\Services\Admin\AdministratorAccess;
use App\Services\Admin\Subscriptions\SubscriptionMonitoring;
use Illuminate\Http\JsonResponse;

class SubscriptionMonitoringController extends Controller
{
    public function __invoke(SubscriptionMonitoringRequest $request, SubscriptionMonitoring $monitor): JsonResponse
    {
        AdministratorAccess::fresh($request->user());
        $page = $monitor->read($request->filters(), $request->integer('per_page', 20), $request->integer('page', 1));

        return response()->json(['data' => SubscriptionMonitoringResource::collection($page['entries']),
            'summary' => $page['summary'], 'meta' => $page['meta']]);
    }
}
