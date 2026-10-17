<?php

namespace App\Http\Requests\Auth;

class ForgotPasswordRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ];
    }
}
