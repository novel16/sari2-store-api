<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Catalog\CatalogRequest;
use Illuminate\Validation\Rule;

class RepaymentRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $notes = trim($this->input('notes'));
            $this->merge(['notes' => $notes === '' ? null : $notes]);
        }
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'method' => ['required', Rule::in(['cash'])],
            'amount_centavos' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'expected_balance_centavos' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/D'],
            'notes' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys(['customer_id', 'sale_id', 'entry_type', 'balance_centavos', 'balance_before_centavos', 'balance_after_centavos', 'payment_number'], ['prohibited']),
            ...$this->protectedFields(),
        ];
    }
}
