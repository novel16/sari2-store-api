<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Active platform-administrator middleware protects the route.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['all', 'active_basic', 'expiring', 'expired_paid', 'free', 'other_paid'])],
            'expiry_days' => ['sometimes', 'integer', Rule::in([7, 30])],
            'account_status' => ['sometimes', Rule::in(['all', 'active', 'suspended'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return ['q' => $this->validated('q') ?? '', 'status' => $this->validated('status') ?? 'all',
            'expiry_days' => $this->integer('expiry_days', 7),
            'account_status' => $this->validated('account_status') ?? 'all'];
    }
}
