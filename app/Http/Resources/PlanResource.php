<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'description' => $this->description,
            'monthly_price_centavos' => $this->monthly_price_centavos, 'active_product_limit' => $this->active_product_limit,
            'quota_scope' => $this->quota_scope, 'store_limit' => $this->store_limit, 'staff_limit' => $this->staff_limit,
            'catalog_status' => $this->catalog_status, 'planned_features' => $this->planned_features ?? [],
            'featured' => $this->featured, 'sort_order' => $this->sort_order, 'version' => $this->version];
    }
}
