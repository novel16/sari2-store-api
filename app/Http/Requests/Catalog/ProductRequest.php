<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class ProductRequest extends CatalogRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'unit' => [$required, Rule::in(['piece', 'pack', 'bottle', 'sachet', 'can', 'box', 'bag'])],
            'price_centavos' => [$required, 'integer', 'min:0', 'max:1000000000'],
            'cost_centavos' => [$required, 'integer', 'min:0', 'max:1000000000'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'initial_stock' => [$this->isMethod('post') ? 'sometimes' : 'prohibited', 'integer', 'min:0', 'max:1000000000'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'emoji' => ['sometimes', 'string', 'max:16'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ...$this->protectedFields(),
        ];
    }
}
