<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'store_id' => $this->store_id,
            'name' => $this->name, 'description' => $this->description,
            'category' => $this->category ? $this->category->only(['id', 'name', 'status']) : null,
            'sku' => $this->sku, 'barcode' => $this->barcode, 'unit' => $this->unit,
            'price_centavos' => $this->price_centavos, 'cost_centavos' => $this->cost_centavos,
            'low_stock_threshold' => $this->low_stock_threshold, 'status' => $this->status,
            'emoji' => $this->emoji, 'color' => $this->color,
            'inventory' => $this->inventory->only(['quantity_on_hand', 'version']),
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
