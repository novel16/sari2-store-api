<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatusChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'target_type' => $this->target_type,
            'target_id' => $this->target_type === 'account' ? $this->account_id : $this->store_id,
            'target_name' => $this->target_name_snapshot, 'account_id' => $this->account_id,
            'from_status' => $this->from_status, 'to_status' => $this->to_status, 'reason' => $this->reason,
            'actor' => ['id' => $this->actor_user_id, 'name' => $this->actor_name_snapshot],
            'created_at' => $this->created_at];
    }
}
