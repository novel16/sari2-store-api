<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name', 'description', 'change_note'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('plans')->ignore($this->route('plan')?->id)],
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:500'],
            'monthly_price_centavos' => ['required', 'integer', 'min:0', 'max:100000000'],
            'active_product_limit' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'quota_scope' => ['required', Rule::in(['store', 'account'])],
            'store_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'staff_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'catalog_status' => ['required', Rule::in(['draft', 'published', 'coming_soon', 'retired'])],
            'planned_features' => ['present', 'array', 'list', 'max:8'],
            'planned_features.*' => ['required', 'string', 'max:80', 'distinct'],
            'featured' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'expected_version' => [$this->route('plan') ? 'required' : 'prohibited', 'integer', 'min:0'],
            'change_note' => ['required', 'string', 'min:3', 'max:255'],
            'is_active' => ['prohibited'], 'ads_enabled' => ['prohibited'], 'entitlements_json' => ['prohibited'],
            'version' => ['prohibited'], 'actor_user_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $plan = $this->route('plan');
            if ($plan && $this->input('code') !== $plan->code) {
                $validator->errors()->add('code', 'The plan code cannot be changed.');
            }
            if ($this->input('code') === 'free') {
                if ((int) $this->input('monthly_price_centavos') !== 0) {
                    $validator->errors()->add('monthly_price_centavos', 'The Free plan must remain free.');
                }
                if ($this->input('catalog_status') !== 'published') {
                    $validator->errors()->add('catalog_status', 'The Free fallback must remain published.');
                }
            } elseif ((int) $this->input('monthly_price_centavos') < 1) {
                $validator->errors()->add('monthly_price_centavos', 'Paid plans must have a price greater than zero.');
            }
            if ($this->input('catalog_status') === 'published' &&
                ((int) $this->input('staff_limit') > 0 || (int) $this->input('store_limit') > 1 || ! empty($this->input('planned_features')))) {
                $validator->errors()->add('catalog_status', 'Staff, multiple stores, and planned features are not available yet. Keep this plan Coming soon or Draft.');
            }
        }];
    }
}
