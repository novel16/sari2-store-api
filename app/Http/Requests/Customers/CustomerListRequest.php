<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Catalog\CatalogRequest;
use Illuminate\Validation\Rule;

class CustomerListRequest extends CatalogRequest
{
    public function rules(): array
    {
        return ['q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'balance' => ['sometimes', Rule::in(['all', 'outstanding', 'settled'])],
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])]];
    }
}
