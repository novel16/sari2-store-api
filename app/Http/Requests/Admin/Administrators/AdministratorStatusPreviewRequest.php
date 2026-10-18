<?php

namespace App\Http\Requests\Admin\Administrators;

use Illuminate\Validation\Rule;

class AdministratorStatusPreviewRequest extends AdministratorRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'suspended'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'], 'name' => ['prohibited'], 'email' => ['prohibited'],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'], 'current_password' => ['prohibited'],
            ...$this->protectedFields()];
    }
}
