<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'cash_day_id' => $this->cash_day_id, 'store_id' => $this->store_id,
            'direction' => $this->direction, 'amount_centavos' => (string) $this->amount_centavos, 'reason' => $this->reason,
            'actor' => ['id' => $this->actor_user_id, 'name' => $this->actor_name_snapshot], 'created_at' => $this->created_at];
    }
}
