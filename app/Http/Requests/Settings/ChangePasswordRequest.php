<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Auth\AuthRequest;
use App\Rules\PasswordInput;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', new PasswordInput],
            'password' => ['bail', 'required', 'string', new PasswordInput, 'confirmed', 'different:current_password', Password::min(8)],
            'user_id' => ['prohibited'], 'id' => ['prohibited'], 'account_id' => ['prohibited'],
            'store_id' => ['prohibited'], 'platform_role' => ['prohibited'], 'status' => ['prohibited'],
            'admin_status_revision' => ['prohibited'],
        ];
    }
}
