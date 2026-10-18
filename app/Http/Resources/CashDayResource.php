<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'store_id' => $this->store_id, 'business_date' => $this->business_date->toDateString(),
            'status' => $this->status, 'opening_cash_centavos' => (string) $this->opening_cash_centavos,
            'notes' => $this->notes, 'opened_at' => $this->opened_at,
            'opened_by' => ['id' => $this->opened_by_user_id, 'name' => $this->opened_by_name_snapshot],
            'closed_at' => $this->closed_at,
            'closed_by' => $this->closed_by_user_id ? ['id' => $this->closed_by_user_id, 'name' => $this->closed_by_name_snapshot] : null,
            'closing' => $this->closing];
    }
}
