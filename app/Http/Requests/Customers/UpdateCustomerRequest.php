<?php

namespace App\Http\Requests\Customers;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['request_key'] = ['prohibited'];

        return $rules;
    }
}
