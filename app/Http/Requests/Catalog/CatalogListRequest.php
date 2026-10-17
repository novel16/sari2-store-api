<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class CatalogListRequest extends CatalogRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'stock' => ['sometimes', Rule::in(['all', 'low', 'out'])],
            'barcode' => ['sometimes', 'string', 'max:64'],
            'ids' => ['sometimes', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
