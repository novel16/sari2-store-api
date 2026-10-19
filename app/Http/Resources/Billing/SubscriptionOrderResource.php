<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pending = in_array($this->payment_status, ['pending', 'unknown', 'creating'], true);
        $owner = ! $request->is('api/v1/admin/*');
        $url = $owner && $pending ? $this->checkout_url : null;

        return ['id' => $this->uuid, 'account_id' => $this->account_id,
            'plan' => ['id' => $this->plan_id, 'version' => $this->plan_version,
                'code' => $this->terms['code'], 'name' => $this->terms['name']],
            'months' => $this->months, 'amount_centavos' => $this->amount_centavos, 'currency' => $this->currency,
            'payment_status' => $this->payment_status, 'fulfillment_status' => $this->fulfillment_status,
            'checkout_url' => $url, 'can_resume' => $url !== null,
            'can_cancel' => $owner && $pending && $this->checkout_session_id !== null,
            'expires_at' => $this->expires_at?->toIso8601String(), 'paid_at' => $this->paid_at?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
            'issue_code' => $this->issue_code,
            'subscription' => $this->whenLoaded('subscription', fn () => $this->subscription ? [
                'id' => $this->subscription->id, 'starts_at' => $this->subscription->starts_at?->toIso8601String(),
                'ends_at' => $this->subscription->ends_at?->toIso8601String(),
            ] : null)];
    }
}
