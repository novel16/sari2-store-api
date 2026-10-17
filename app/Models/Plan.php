<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'monthly_price_centavos', 'active_product_limit',
        'store_limit', 'staff_limit', 'ads_enabled', 'entitlements_json', 'is_active',
        'description', 'catalog_status', 'quota_scope', 'planned_features', 'featured', 'sort_order', 'version',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price_centavos' => 'integer',
            'active_product_limit' => 'integer',
            'store_limit' => 'integer',
            'staff_limit' => 'integer',
            'ads_enabled' => 'boolean',
            'is_active' => 'boolean',
            'entitlements_json' => 'array',
            'planned_features' => 'array', 'featured' => 'boolean', 'sort_order' => 'integer', 'version' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }
}
