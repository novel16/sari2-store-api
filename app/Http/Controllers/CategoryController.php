<?php

namespace App\Http\Controllers;

use App\Http\Requests\Catalog\CatalogListRequest;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Store;
use App\Services\Catalog\CategoryWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(CatalogListRequest $request, Store $store): AnonymousResourceCollection
    {
        $query = $store->categories()->withCount('products');
        if ($request->input('status', 'active') !== 'all') {
            $query->where('status', $request->input('status', 'active'));
        }
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        return CategoryResource::collection($query->orderBy('name')->orderBy('id')
            ->paginate($request->integer('per_page', 12))->withQueryString());
    }

    public function store(CategoryRequest $request, Store $store, CategoryWriter $writer): JsonResponse
    {
        return (new CategoryResource($writer->save($store, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, Store $store, Category $category, CategoryWriter $writer): CategoryResource
    {
        return new CategoryResource($writer->save($store, $request->validated(), $category));
    }

    public function destroy(Store $store, Category $category, CategoryWriter $writer): CategoryResource
    {
        return new CategoryResource($writer->save($store, ['status' => 'archived'], $category));
    }
}
