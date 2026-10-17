<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends CatalogRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(['add', 'remove', 'set'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'expected_version' => ['required_if:mode,set', 'integer', 'min:0', 'max:4294967294'],
            'reason' => ['required', 'string', 'max:255'],
            'request_key' => ['required', 'uuid'],
            ...$this->protectedFields(),
        ];
    }
}
