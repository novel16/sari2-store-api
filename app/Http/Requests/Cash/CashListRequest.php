<?php

namespace App\Http\Requests\Cash;

class CashListRequest extends CashRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
