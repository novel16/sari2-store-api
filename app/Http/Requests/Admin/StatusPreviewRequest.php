<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'suspended'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            ...StatusChangeRequest::protectedFields(), 'review_token' => ['prohibited'], 'request_key' => ['prohibited']];
    }
}
