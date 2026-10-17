<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Catalog\CatalogRequest;

class StatementRequest extends CatalogRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'through_id' => ['sometimes', 'integer', 'min:0']];
    }
}
