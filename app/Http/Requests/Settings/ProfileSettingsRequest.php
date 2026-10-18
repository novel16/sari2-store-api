<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Auth\AuthRequest;
use App\Models\User;
use App\Rules\PasswordInput;
use Illuminate\Validation\Rule;

class ProfileSettingsRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:120'],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:254', Rule::unique(User::class)->ignore($this->user()->id)],
            'current_password' => ['bail', 'sometimes', 'nullable', 'string', new PasswordInput],
            'id' => ['prohibited'], 'user_id' => ['prohibited'], 'account_id' => ['prohibited'],
            'store_id' => ['prohibited'], 'platform_role' => ['prohibited'], 'role' => ['prohibited'],
            'status' => ['prohibited'], 'email_verified_at' => ['prohibited'], 'password' => ['prohibited'],
            'admin_status_revision' => ['prohibited'],
        ];
    }
}
