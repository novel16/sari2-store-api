<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Catalog\CatalogRequest;
use Illuminate\Validation\Rule;

class CustomerStatusRequest extends CatalogRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'archived'])],
            ...array_fill_keys(['name', 'phone', 'notes', 'balance_centavos', 'request_key'], ['prohibited']),
            ...$this->protectedFields()];
    }
}
