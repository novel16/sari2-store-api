<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sales\ReverseSaleRequest;
use App\Http\Resources\SaleResource;
use App\Http\Resources\SaleReversalResource;
use App\Models\Sale;
use App\Models\SaleReversal;
use App\Models\Store;
use App\Services\Sales\ReverseSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SaleReversalController extends Controller
{
    public function preview(Store $store, Sale $sale, ReverseSale $reverse): JsonResponse
    {
        return response()->json(['data' => $reverse->preview($store, $sale)]);
    }

    public function store(ReverseSaleRequest $request, Store $store, Sale $sale, ReverseSale $reverse): JsonResponse
    {
        $result = $reverse->handle($store, $sale, $request->user(), $request->validated());

        return response()->json(['data' => new SaleResource($result['sale']), 'reversal' => new SaleReversalResource($result['reversal']),
            'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function byKey(Store $store, string $key): JsonResponse
    {
        abort_unless(Str::isUuid($key), 404);
        $reversal = SaleReversal::where('store_id', $store->id)->where('request_key', Str::lower($key))->firstOrFail();

        return response()->json(['data' => new SaleResource($reversal->sale()->with(['items', 'payment', 'creditEntry', 'reversal'])->firstOrFail()),
            'reversal' => new SaleReversalResource($reversal), 'meta' => ['replayed' => true]]);
    }
}
