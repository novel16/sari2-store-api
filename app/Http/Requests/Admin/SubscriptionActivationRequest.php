<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('request_key'))) {
            $this->merge(['request_key' => strtolower($this->input('request_key'))]);
        }
    }

    public function rules(): array
    {
        return ['quote' => ['required', 'string', 'max:20000'], 'request_key' => ['required', 'uuid'],
            'actor_user_id' => ['prohibited'], 'plan_id' => ['prohibited'], 'amount_centavos' => ['prohibited'],
            'starts_at' => ['prohibited'], 'ends_at' => ['prohibited'], 'status' => ['prohibited']];
    }
}
