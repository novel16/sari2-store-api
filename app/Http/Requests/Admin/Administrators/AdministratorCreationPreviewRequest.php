<?php

namespace App\Http\Requests\Admin\Administrators;

class AdministratorCreationPreviewRequest extends AdministratorRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'reason' => ['required', 'string', 'min:3', 'max:500'], 'status' => ['prohibited'],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'], 'current_password' => ['prohibited'],
            ...$this->protectedFields()];
    }
}
