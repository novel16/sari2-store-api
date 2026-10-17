<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'status' => $this->status,
            'owner' => $this->owner->only(['id', 'name', 'email', 'status']),
            'stores_count' => (int) $this->stores_count,
            'members_count' => (int) $this->memberships_count,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
