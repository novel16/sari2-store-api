<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'payment_reference'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => $key === 'payment_reference' ? strtoupper(trim($this->input($key))) : trim($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        $paid = $this->input('settlement_method') === 'manual_payment';

        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'months' => ['required', 'integer', 'min:1', 'max:12'],
            'settlement_method' => ['required', Rule::in(['manual_payment', 'complimentary'])],
            'amount_centavos' => ['required', 'integer', 'min:0', 'max:1200000000', ...($paid ? [] : ['in:0'])],
            'payment_reference' => [$paid ? 'required' : 'prohibited', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._:\/-]*$/'],
            'payment_verified' => $paid ? ['required', 'accepted'] : ['prohibited'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'actor_user_id' => ['prohibited'], 'starts_at' => ['prohibited'], 'ends_at' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }
}
