<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return ['q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment_status' => ['sometimes', Rule::in(['all', 'creating', 'pending', 'unknown', 'paid', 'expired', 'failed'])],
            'fulfillment_status' => ['sometimes', Rule::in(['all', 'pending', 'fulfilled', 'needs_attention'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
