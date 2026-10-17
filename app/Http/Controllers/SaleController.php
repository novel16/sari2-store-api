<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sales\CheckoutRequest;
use App\Http\Requests\Sales\SalesListRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\Store;
use App\Services\Sales\CompleteSale;
use App\Services\Sales\SalesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class SaleController extends Controller
{
    public function index(SalesListRequest $request, Store $store, SalesQuery $sales): AnonymousResourceCollection
    {
        $query = $sales->forStore($store, $request->validated());
        $snapshot = (clone $query)->max('id');
        $query->where('id', '<=', $snapshot ?? 0);
        $summary = $sales->summary($query);

        return SaleResource::collection($query->with('payment')->orderByDesc('completed_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))->withQueryString())->additional(['meta' => ['summary' => $summary, 'snapshot_id' => $snapshot ? (int) $snapshot : null]]);
    }

    public function store(CheckoutRequest $request, Store $store, CompleteSale $checkout): JsonResponse
    {
        $result = $checkout->handle($store, $request->user(), $request->validated());

        return response()->json(['data' => new SaleResource($result['sale']), 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function show(Store $store, Sale $sale): SaleResource
    {
        return new SaleResource($sale->load(['items', 'payment', 'creditEntry']));
    }

    public function byKey(Store $store, string $key): SaleResource
    {
        abort_unless(Str::isUuid($key), 404);

        return new SaleResource($store->sales()->where('idempotency_key', Str::lower($key))->with(['items', 'payment', 'creditEntry'])->firstOrFail());
    }
}
