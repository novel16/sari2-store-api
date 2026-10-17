<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sale_id' => $this->sale_id, 'sale_number' => $this->sale?->sale_number,
            'reversal' => $this->whenLoaded('reversal', fn () => $this->reversal ? ['reference' => 'REV-'.str_pad((string) $this->reversal->id, 8, '0', STR_PAD_LEFT), 'sale_id' => $this->reversal->sale_id, 'sale_number' => $this->reversal->sale?->sale_number] : null),
            'entry_type' => $this->entry_type, 'amount_centavos' => $this->amount_centavos,
            'payment_number' => $this->entry_type === 'payment' ? 'PAY-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT) : null,
            'opening_number' => $this->entry_type === 'opening_balance' ? 'OPEN-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT) : null,
            'payment_method' => $this->payment_method, 'notes' => $this->notes,
            'customer' => $this->customer_name_snapshot !== null ? ['id' => $this->customer_id, 'name' => $this->customer_name_snapshot, 'phone' => $this->customer_phone_snapshot] : null,
            'recorded_by' => ['id' => $this->actor_user_id, 'name' => $this->actor_name_snapshot ?? $this->sale?->sold_by_name_snapshot ?? $this->actor?->name],
            'balance_before_centavos' => (string) $this->balance_before_centavos,
            'balance_after_centavos' => (string) $this->balance_after_centavos, 'created_at' => $this->created_at];
    }
}
