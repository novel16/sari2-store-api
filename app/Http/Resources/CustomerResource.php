<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'store_id' => $this->store_id, 'name' => $this->name,
            'phone' => $this->phone, 'notes' => $this->notes, 'status' => $this->status,
            'balance_centavos' => (string) $this->balance_centavos, 'created_at' => $this->created_at,
            'can_record_opening_balance' => $this->whenHas('ledger_entries_exists', fn ($exists) => $this->status === 'active' && $this->balance_centavos === 0 && ! $exists)];
    }
}
