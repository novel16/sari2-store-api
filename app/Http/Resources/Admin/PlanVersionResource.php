<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'terms' => $this->terms,
            'change_note' => $this->change_note, 'actor' => $this->actor?->only(['id', 'name']), 'created_at' => $this->created_at];
    }
}
