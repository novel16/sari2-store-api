<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reports\SalesReportRequest;
use App\Models\Store;
use App\Services\Reports\SalesReport;
use Illuminate\Http\JsonResponse;

class SalesReportController extends Controller
{
    public function __invoke(SalesReportRequest $request, Store $store, SalesReport $report): JsonResponse
    {
        return response()->json(['data' => $report->forStore($store, $request->validated('date_from'), $request->validated('date_to'))]);
    }
}
