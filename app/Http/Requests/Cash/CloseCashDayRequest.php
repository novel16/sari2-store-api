<?php

namespace App\Http\Requests\Cash;

class CloseCashDayRequest extends CashRequest
{
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'review_token' => ['required', 'string', 'max:20000'],
            ...$this->cashProtectedFields(['review_token'])];
    }
}
