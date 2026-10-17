<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReversalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sale_id' => $this->sale_id,
            'reference' => 'REV-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT),
            'kind' => $this->kind, 'reason' => $this->reason, 'restock' => $this->restock,
            'total_centavos' => $this->total_centavos, 'cash_refund_centavos' => $this->cash_refund_centavos,
            'credit_reversed_centavos' => $this->credit_reversed_centavos,
            'balance_before_centavos' => $this->balance_before_centavos === null ? null : (string) $this->balance_before_centavos,
            'balance_after_centavos' => $this->balance_after_centavos === null ? null : (string) $this->balance_after_centavos,
            'recorded_by' => ['id' => $this->actor_user_id, 'name' => $this->actor_name_snapshot], 'created_at' => $this->created_at];
    }
}
