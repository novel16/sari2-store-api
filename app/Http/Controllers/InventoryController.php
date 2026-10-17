<?php

namespace App\Http\Controllers;

use App\Http\Requests\Catalog\CatalogListRequest;
use App\Http\Requests\Catalog\StockAdjustmentRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\ProductResource;
use App\Models\Store;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function index(CatalogListRequest $request, Store $store, CatalogQuery $catalog): AnonymousResourceCollection
    {
        return ProductResource::collection($catalog->products($store, $request->validated())
            ->paginate($request->integer('per_page', 20))->withQueryString())
            ->additional(['meta' => ['summary' => $catalog->summary($store)]]);
    }

    public function movements(CatalogListRequest $request, Store $store): AnonymousResourceCollection
    {
        $query = $store->inventoryMovements()->with(['product', 'actor', 'reversal']);
        if ($request->filled('product_id')) {
            $product = $store->products()->findOrFail($request->integer('product_id'));
            $query->where('product_id', $product->id);
        }

        return InventoryMovementResource::collection($query->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function adjust(StockAdjustmentRequest $request, Store $store, StockAdjustment $adjustment): JsonResponse
    {
        $result = $adjustment->handle($store, $request->user(), $request->validated());

        return response()->json([
            'data' => new InventoryMovementResource($result['movement']),
            'product' => new ProductResource($result['movement']->product),
            'meta' => ['replayed' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }
}
