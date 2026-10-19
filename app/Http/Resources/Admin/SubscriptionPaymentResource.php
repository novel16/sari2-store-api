<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Billing\SubscriptionOrderResource;
use Illuminate\Http\Request;

class SubscriptionPaymentResource extends SubscriptionOrderResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request),
            'account' => ['id' => $this->account->id, 'name' => $this->account->name, 'status' => $this->account->status],
            'owner' => ['id' => $this->owner->id, 'name' => $this->owner->name, 'email' => $this->owner->email]];
    }
}
