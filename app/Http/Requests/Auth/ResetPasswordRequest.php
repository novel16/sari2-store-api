<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordInput;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['bail', 'required', 'string', new PasswordInput, 'confirmed', 'max:72', Password::min(8)],
        ];
    }
}
