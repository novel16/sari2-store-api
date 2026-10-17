<?php

namespace App\Http\Controllers;

use App\Http\Requests\Catalog\CatalogListRequest;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\ProductQuota;
use App\Services\Catalog\ProductWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(CatalogListRequest $request, Store $store, CatalogQuery $catalog, ProductQuota $quota): AnonymousResourceCollection
    {
        return ProductResource::collection($catalog->products($store, $request->validated())
            ->paginate($request->integer('per_page', 24))->withQueryString())
            ->additional(['meta' => ['summary' => $catalog->summary($store), 'quota' => $quota->forStore($store)]]);
    }

    public function store(ProductRequest $request, Store $store, ProductWriter $writer): JsonResponse
    {
        return (new ProductResource($writer->save($store, $request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Store $store, Product $product): ProductResource
    {
        return new ProductResource($product->load(['category', 'inventory']));
    }

    public function lookup(Request $request, Store $store): ProductResource
    {
        $data = $request->validate(['barcode' => ['required', 'string', 'max:64']]);

        return new ProductResource($store->products()->where('status', 'active')
            ->where('barcode', Str::upper(trim($data['barcode'])))->with(['category', 'inventory'])->firstOrFail());
    }

    public function update(ProductRequest $request, Store $store, Product $product, ProductWriter $writer): ProductResource
    {
        return new ProductResource($writer->save($store, $request->user(), $request->validated(), $product));
    }

    public function destroy(Request $request, Store $store, Product $product, ProductWriter $writer): ProductResource
    {
        return new ProductResource($writer->save($store, $request->user(), ['status' => 'archived'], $product));
    }
}
