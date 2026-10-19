<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class CreateSubscriptionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'plan_id' => ['required', 'integer', 'min:1'],
            'expected_plan_version' => ['required', 'integer', 'min:1'], 'months' => ['required', 'integer', 'min:1', 'max:12'],
            'account_id' => ['prohibited'], 'owner_user_id' => ['prohibited'], 'amount_centavos' => ['prohibited'],
            'currency' => ['prohibited'], 'payment_status' => ['prohibited'], 'paid' => ['prohibited'],
            'status' => ['prohibited'], 'checkout_session_id' => ['prohibited'], 'payment_id' => ['prohibited'],
            'subscription_id' => ['prohibited'], 'fulfillment_status' => ['prohibited'], 'paid_at' => ['prohibited'],
            'starts_at' => ['prohibited'], 'ends_at' => ['prohibited'], 'checkout_url' => ['prohibited']];
    }
}
