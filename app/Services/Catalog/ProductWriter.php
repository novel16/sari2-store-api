<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductWriter
{
    public function __construct(private ProductQuota $quota) {}

    public function save(Store $store, User $actor, array $attributes, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($store, $actor, $attributes, $product): Product {
            $store = StoreWriteAccess::lock($store);
            $product = $product ? $store->products()->whereKey($product->id)->lockForUpdate()->firstOrFail() : $store->products()->make();
            $creating = ! $product->exists;
            if (! $creating && array_key_exists('unit', $attributes) && $attributes['unit'] !== $product->unit
                && ($product->inventory()->where('store_id', $store->id)->where('quantity_on_hand', '>', 0)->exists()
                    || $product->movements()->where('store_id', $store->id)->exists()
                    || DB::table('sale_items')->where('store_id', $store->id)->where('product_id', $product->id)->exists())) {
                throw ValidationException::withMessages(['unit' => ['The unit cannot change after stock or history has been recorded. Create a separate product for a different unit.']]);
            }
            $status = $attributes['status'] ?? ($creating ? 'active' : $product->status);
            if ($status === 'active' && ($creating || $product->status !== 'active')) {
                $this->quota->ensureRoom($store);
            }

            $categoryId = array_key_exists('category_id', $attributes) ? $attributes['category_id'] : $product->category_id;
            if ($categoryId && (array_key_exists('category_id', $attributes) || $status === 'active')) {
                $category = $store->categories()->whereKey($categoryId)->where('status', 'active')->first();
                if (! $category) {
                    throw ValidationException::withMessages(['category_id' => ['Choose an active category from this store.']]);
                }
            }

            foreach (['sku', 'barcode'] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $attributes[$field] = filled($attributes[$field]) ? Str::upper(trim($attributes[$field])) : null;
                if ($attributes[$field] && $store->products()->where($field, $attributes[$field])
                    ->when($product->exists, fn ($query) => $query->whereKeyNot($product->id))->exists()) {
                    throw ValidationException::withMessages([$field => ["This {$field} is already assigned to a product, including archived products."]]);
                }
            }
            $product->fill(Arr::except($attributes, ['initial_stock']))->save();
            if ($creating) {
                $quantity = $attributes['initial_stock'] ?? 0;
                $product->inventory()->create(['store_id' => $store->id, 'quantity_on_hand' => $quantity, 'version' => 1]);
                $product->movements()->create([
                    'store_id' => $store->id, 'actor_user_id' => $actor->id, 'movement_type' => 'opening',
                    'quantity_delta' => $quantity, 'quantity_before' => 0, 'quantity_after' => $quantity,
                    'version_after' => 1, 'reason' => 'Opening stock',
                ]);
            }

            return $product->refresh()->load(['category', 'inventory']);
        }, 3);
    }
}
