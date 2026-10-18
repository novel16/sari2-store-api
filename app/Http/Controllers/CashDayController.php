<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cash\CashListRequest;
use App\Http\Requests\Cash\CloseCashDayPreviewRequest;
use App\Http\Requests\Cash\CloseCashDayRequest;
use App\Http\Requests\Cash\OpenCashDayRequest;
use App\Http\Resources\CashDayResource;
use App\Models\CashDay;
use App\Models\Store;
use App\Services\Cash\CashDayGuard;
use App\Services\Cash\CashDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashDayController extends Controller
{
    public function current(Store $store, CashDayService $cash): JsonResponse
    {
        $result = $cash->current($store);

        return response()->json(['data' => $result['day'] ? new CashDayResource($result['day']) : null,
            'business_date' => $result['business_date'], 'time_zone' => CashDayGuard::TIMEZONE, 'totals' => $result['totals']]);
    }

    public function index(CashListRequest $request, Store $store): AnonymousResourceCollection
    {
        return CashDayResource::collection($store->cashDays()->orderByDesc('business_date')->orderByDesc('id')->paginate($request->integer('per_page', 20)));
    }

    public function store(OpenCashDayRequest $request, Store $store, CashDayService $cash): JsonResponse
    {
        return $this->response($cash->open($store, $request->user(), $request->validated()));
    }

    public function show(Store $store, CashDay $cashDay, CashDayService $cash): JsonResponse
    {
        return $this->response($cash->read($store, $cashDay), 200);
    }

    public function preview(CloseCashDayPreviewRequest $request, Store $store, CashDay $cashDay, CashDayService $cash): JsonResponse
    {
        return response()->json(['data' => $cash->preview($store, $cashDay, $request->user(), $request->validated())]);
    }

    public function close(CloseCashDayRequest $request, Store $store, CashDay $cashDay, CashDayService $cash): JsonResponse
    {
        return $this->response($cash->close($store, $cashDay, $request->user(), $request->validated()));
    }

    public function byKey(Store $store, string $key, CashDayService $cash): JsonResponse
    {
        return $this->response($cash->openingByKey($store, $key), 200);
    }

    public function closingByKey(Store $store, string $key, CashDayService $cash): JsonResponse
    {
        return $this->response($cash->closingByKey($store, $key), 200);
    }

    private function response(array $result, ?int $status = null): JsonResponse
    {
        return response()->json(['data' => new CashDayResource($result['day']), 'totals' => $result['totals'],
            'meta' => ['replayed' => $result['replayed']]], $status ?? ($result['replayed'] ? 200 : 201));
    }
}
