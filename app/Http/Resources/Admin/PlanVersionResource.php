<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'terms' => $this->terms,
            'change_note' => $this->change_note, 'actor' => $this->actor_user_id === null ? null : [
                'id' => $this->actor_user_id,
                'name' => $this->actor_name_snapshot ?? 'Administrator #'.$this->actor_user_id.' (name not recorded)',
            ], 'created_at' => $this->created_at];
    }
}
