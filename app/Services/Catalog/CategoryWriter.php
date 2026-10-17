<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryWriter
{
    public function save(Store $store, array $attributes, ?Category $category = null): Category
    {
        return DB::transaction(function () use ($store, $attributes, $category): Category {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $category = $category ? $store->categories()->whereKey($category->id)->lockForUpdate()->firstOrFail() : $store->categories()->make();
            if (isset($attributes['name'])) {
                $attributes['name_key'] = Str::lower(trim($attributes['name']));
                $duplicate = $store->categories()->where('name_key', $attributes['name_key'])
                    ->when($category->exists, fn ($query) => $query->whereKeyNot($category->id))->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['name' => ['This category name is already in use, including archived categories.']]);
                }
            }
            if (($attributes['status'] ?? $category->status) === 'archived' && $category->exists) {
                abort_if($category->products()->where('status', 'active')->exists(), 409,
                    'Move active products out of this category before archiving it.');
            }
            $category->fill($attributes)->save();

            return $category->refresh()->loadCount('products');
        }, 3);
    }
}
