<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reversal = $this->relationLoaded('reversal') ? $this->reversal : null;

        return [
            'id' => $this->id, 'product' => $this->product->only(['id', 'name', 'unit']),
            'actor' => ['id' => $this->actor_user_id, 'name' => $reversal?->actor_name_snapshot ?? $this->actor->name],
            'movement_type' => $this->movement_type, 'quantity_delta' => $this->quantity_delta,
            'quantity_before' => $this->quantity_before, 'quantity_after' => $this->quantity_after,
            'version_after' => $this->version_after, 'reason' => $this->reason,
            'created_at' => $this->created_at,
            'sale_id' => $this->sale_id,
            'reversal_sale_id' => $reversal?->sale_id,
            'reversal_reference' => $reversal ? 'REV-'.str_pad((string) $reversal->id, 8, '0', STR_PAD_LEFT) : null,
        ];
    }
}
