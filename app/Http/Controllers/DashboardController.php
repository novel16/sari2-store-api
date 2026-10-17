<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dashboard\DashboardRequest;
use App\Models\Store;
use App\Services\Dashboard\DashboardSummary;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, Store $store, DashboardSummary $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->forStore($store, $request->validated('period', 'this_week'))]);
    }
}
