<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionActivationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'subscription_id' => $this->subscription_id,
            'action' => $this->action, 'settlement_method' => $this->settlement_method,
            'months' => $this->months, 'amount_centavos' => $this->amount_centavos,
            'payment_reference' => $this->payment_reference, 'reason' => $this->reason,
            'snapshot' => $this->snapshot, 'actor' => ['id' => $this->actor_user_id, 'name' => $this->actor_name_snapshot],
            'created_at' => $this->created_at];
    }
}
