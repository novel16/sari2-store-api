<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Catalog\CatalogRequest;
use Illuminate\Validation\Rule;

class ReverseSaleRequest extends CatalogRequest
{
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['void', 'refund'])],
            'reason' => ['required', 'string', 'min:3', 'max:255'], 'restock' => ['required', 'boolean'],
            'expected_balance_centavos' => ['present', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'],
            'cash_confirmed' => ['required', 'boolean'], 'cash_refund_centavos' => ['prohibited'],
            'credit_reversed_centavos' => ['prohibited'], 'sale_id' => ['prohibited'], ...$this->protectedFields()];
    }
}
