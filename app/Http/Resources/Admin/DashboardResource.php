<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'summary' => $this->resource['summary'],
            'registrations' => $this->resource['registrations'],
            'timezone' => $this->resource['timezone'],
            'date_from' => $this->resource['date_from'],
            'date_to' => $this->resource['date_to'],
            'recent_accounts' => AccountResource::collection($this->resource['recent_accounts']),
            'recent_stores' => StoreResource::collection($this->resource['recent_stores']),
            'generated_at' => $this->resource['generated_at'],
        ];
    }
}
