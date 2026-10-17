<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Store;
use App\Services\Plans\EffectivePlan;

class ProductQuota
{
    public function __construct(private readonly EffectivePlan $plans) {}

    public function forStore(Store $store): array
    {
        $store->loadMissing('account');

        return $this->usage($store, $this->plans->forAccount($store->account)['terms']);
    }

    public function usage(Store $store, array $terms): array
    {
        $query = Product::where('status', 'active');
        if ($terms['quota_scope'] === 'account') {
            $query->whereHas('store', fn ($query) => $query->where('account_id', $store->account_id));
        } else {
            $query->where('store_id', $store->id);
        }
        $used = $query->count();
        $limit = $terms['active_product_limit'];

        return [
            'plan_name' => $terms['name'], 'active_product_limit' => $limit, 'active_products' => $used,
            'quota_scope' => $terms['quota_scope'], 'remaining' => $limit === null ? null : max(0, $limit - $used),
            'over_limit' => $limit !== null && $used > $limit,
        ];
    }

    public function ensureRoom(Store $store): void
    {
        // The caller holds account + store locks, including pooled quotas across branches.
        $quota = $this->forStore($store);
        abort_if($quota['active_product_limit'] !== null && $quota['active_products'] >= $quota['active_product_limit'],
            409, 'Your active-product limit has been reached. Archive a product before adding or restoring another.');
    }
}
