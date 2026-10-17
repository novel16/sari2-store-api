<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Catalog\CatalogRequest;

class OpeningBalanceRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes'))]);
        }
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'amount_centavos' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'notes' => ['required', 'string', 'min:3', 'max:255'],
            ...array_fill_keys(['customer_id', 'sale_id', 'entry_type', 'balance_centavos', 'balance_before_centavos', 'balance_after_centavos', 'payment_method', 'created_at'], ['prohibited']),
            ...$this->protectedFields()];
    }
}
