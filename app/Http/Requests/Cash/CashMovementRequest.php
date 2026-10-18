<?php

namespace App\Http\Requests\Cash;

use Illuminate\Validation\Rule;

class CashMovementRequest extends CashRequest
{
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'direction' => ['required', Rule::in(['in', 'out'])],
            'amount_centavos' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            ...$this->cashProtectedFields(['direction', 'amount_centavos', 'reason'])];
    }
}
