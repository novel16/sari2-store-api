<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Catalog\CatalogRequest;

class SalesListRequest extends CatalogRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'through_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
