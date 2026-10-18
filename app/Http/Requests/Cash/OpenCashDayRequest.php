<?php

namespace App\Http\Requests\Cash;

class OpenCashDayRequest extends CashRequest
{
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'business_date' => ['required', 'date_format:Y-m-d'],
            'opening_cash_centavos' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            ...$this->cashProtectedFields(['business_date', 'opening_cash_centavos', 'notes'])];
    }
}
