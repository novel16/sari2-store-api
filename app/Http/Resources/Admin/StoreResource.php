<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'account_id' => $this->account_id, 'name' => $this->name,
            'slug' => $this->slug, 'status' => $this->status, 'phone' => $this->phone, 'address' => $this->address,
            'account' => [
                'id' => $this->account->id, 'name' => $this->account->name, 'status' => $this->account->status,
                'owner' => $this->account->owner->only(['id', 'name', 'email', 'status']),
            ],
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
