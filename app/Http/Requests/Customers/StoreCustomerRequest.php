<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Catalog\CatalogRequest;

class StoreCustomerRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:500'],
            'balance_centavos' => ['prohibited'], 'status' => ['prohibited'], ...$this->protectedFields()];
    }
}
