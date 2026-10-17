<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class PlanResource extends \App\Http\Resources\PlanResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'subscriptions_count' => $this->whenCounted('subscriptions'),
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
