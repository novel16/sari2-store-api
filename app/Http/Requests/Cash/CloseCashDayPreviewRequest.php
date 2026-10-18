<?php

namespace App\Http\Requests\Cash;

class CloseCashDayPreviewRequest extends CashRequest
{
    public function rules(): array
    {
        return ['counted_cash_centavos' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'], 'request_key' => ['prohibited'],
            ...$this->cashProtectedFields(['counted_cash_centavos', 'notes'])];
    }
}
