<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cash\CashListRequest;
use App\Http\Requests\Cash\CashMovementRequest;
use App\Http\Resources\CashDayResource;
use App\Http\Resources\CashMovementResource;
use App\Models\CashDay;
use App\Models\Store;
use App\Services\Cash\CashDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashMovementController extends Controller
{
    public function index(CashListRequest $request, Store $store, CashDay $cashDay): AnonymousResourceCollection
    {
        return CashMovementResource::collection($cashDay->movements()->where('store_id', $store->id)
            ->orderByDesc('id')->paginate($request->integer('per_page', 20)));
    }

    public function store(CashMovementRequest $request, Store $store, CashDay $cashDay, CashDayService $cash): JsonResponse
    {
        return $this->response($cash->move($store, $cashDay, $request->user(), $request->validated()));
    }

    public function byKey(Store $store, string $key, CashDayService $cash): JsonResponse
    {
        return $this->response($cash->movementByKey($store, $key), 200);
    }

    private function response(array $result, ?int $status = null): JsonResponse
    {
        return response()->json(['data' => new CashMovementResource($result['movement']), 'day' => new CashDayResource($result['day']),
            'totals' => $result['totals'], 'meta' => ['replayed' => $result['replayed']]], $status ?? ($result['replayed'] ? 200 : 201));
    }
}
