<?php

namespace App\Http\Requests\Admin\Administrators;

use App\Http\Requests\Auth\AuthRequest;

abstract class AdministratorRequest extends AuthRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        foreach (['name', 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    protected function protectedFields(): array
    {
        return array_fill_keys(['id', 'user_id', 'administrator_user_id', 'actor_user_id', 'actor', 'account_id',
            'store_id', 'memberships', 'accounts', 'stores', 'platform_role', 'email_verified_at', 'remember_token',
            'admin_status_revision', 'request_hash', 'review_hash', 'created_at', 'updated_at', 'result_snapshot',
            'from_status', 'to_status', 'active_administrators', 'administrator', 'kind', 'expires_at'], ['prohibited']);
    }
}
