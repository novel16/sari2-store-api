<?php

namespace App\Http\Requests\Admin\Administrators;

use App\Rules\PasswordInput;

class AdministratorStatusChangeRequest extends AdministratorRequest
{
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'review_token' => ['required', 'string', 'max:20000'],
            'current_password' => ['bail', 'required', 'string', new PasswordInput],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'],
            'name' => ['prohibited'], 'email' => ['prohibited'], 'reason' => ['prohibited'], 'status' => ['prohibited'],
            ...$this->protectedFields()];
    }
}
