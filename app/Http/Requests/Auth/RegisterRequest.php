<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Rules\PasswordInput;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'store_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique(User::class)],
            'password' => ['bail', 'required', 'string', new PasswordInput, 'confirmed', 'max:72', Password::min(8)],
            'platform_role' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
            'user_id' => ['prohibited'],
            'account_id' => ['prohibited'],
            'store_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'plan_id' => ['prohibited'],
            'account' => ['prohibited'],
            'accounts' => ['prohibited'],
            'store' => ['prohibited'],
            'stores' => ['prohibited'],
            'memberships' => ['prohibited'],
            'subscription' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
        ];
    }
}
