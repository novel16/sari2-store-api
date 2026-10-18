<?php

namespace App\Http\Requests\Admin\Administrators;

use App\Rules\PasswordInput;
use Illuminate\Validation\Rules\Password;

class AdministratorCreationRequest extends AdministratorRequest
{
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'review_token' => ['required', 'string', 'max:20000'],
            'current_password' => ['bail', 'required', 'string', new PasswordInput],
            'password' => ['bail', 'required', 'string', new PasswordInput, 'confirmed', 'max:72', Password::min(8)],
            'name' => ['prohibited'], 'email' => ['prohibited'], 'reason' => ['prohibited'], 'status' => ['prohibited'],
            ...$this->protectedFields()];
    }
}
