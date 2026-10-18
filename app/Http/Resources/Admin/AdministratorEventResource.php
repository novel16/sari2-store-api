<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdministratorEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'action' => $this->action,
            'administrator' => ['id' => $this->administrator_user_id, 'name' => $this->administrator_name_snapshot,
                'email' => $this->administrator_email_snapshot],
            'from_status' => $this->from_status, 'to_status' => $this->to_status, 'reason' => $this->reason,
            'actor' => ['id' => $this->actor_user_id, 'name' => $this->actor_name_snapshot], 'created_at' => $this->created_at];
    }
}
