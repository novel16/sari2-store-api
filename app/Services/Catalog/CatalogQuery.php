<?php

namespace App\Services\Catalog;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

class CatalogQuery
{
    public function products(Store $store, array $filters): Builder
    {
        $query = $store->products()->getQuery()->with(['category', 'inventory']);
        if (! empty($filters['ids'])) {
            $query->whereIn('id', $filters['ids']);
        }
        if (($filters['status'] ?? 'active') !== 'all') {
            $query->where('status', $filters['status'] ?? 'active');
        }
        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term)
                ->orWhere('sku', 'like', $term)->orWhere('barcode', 'like', $term)
                ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $term)));
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (($filters['stock'] ?? 'all') === 'low') {
            $query->whereHas('inventory', fn ($stock) => $stock->where('quantity_on_hand', '>', 0)
                ->whereColumn('quantity_on_hand', '<=', 'products.low_stock_threshold'));
        } elseif (($filters['stock'] ?? 'all') === 'out') {
            $query->whereHas('inventory', fn ($stock) => $stock->where('quantity_on_hand', 0));
        }

        return $query->orderBy('name')->orderBy('id');
    }

    public function summary(Store $store): array
    {
        $totals = $store->products()->where('products.status', 'active')
            ->join('inventories', 'inventories.product_id', '=', 'products.id')
            ->selectRaw('COUNT(*) AS active_products, COALESCE(SUM(quantity_on_hand), 0) AS total_units,
                COALESCE(SUM(CASE WHEN quantity_on_hand > 0 AND quantity_on_hand <= low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock,
                COALESCE(SUM(CASE WHEN quantity_on_hand = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
                COALESCE(SUM(quantity_on_hand * cost_centavos), 0) AS inventory_value_centavos')->first();

        return [
            'active_products' => (int) $totals->active_products,
            'archived_products' => $store->products()->where('status', 'archived')->count(),
            'total_units' => (int) $totals->total_units, 'low_stock' => (int) $totals->low_stock,
            'out_of_stock' => (int) $totals->out_of_stock,
            'inventory_value_centavos' => (string) $totals->inventory_value_centavos,
        ];
    }
}
