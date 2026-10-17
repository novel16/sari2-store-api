<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordInput;

class LoginRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['bail', 'required', 'string', new PasswordInput, 'min:1', 'max:72'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
