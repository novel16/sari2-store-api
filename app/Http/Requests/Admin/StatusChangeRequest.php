<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StatusChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'review_token' => ['required', 'string', 'max:20000'],
            'status' => ['prohibited'], 'reason' => ['prohibited'], ...self::protectedFields()];
    }

    public static function protectedFields(): array
    {
        return array_fill_keys(['actor_user_id', 'actor', 'account_id', 'store_id', 'target_type', 'target_id',
            'target_name', 'from_status', 'to_status', 'status_revision', 'parent_status', 'impact',
            'created_at', 'request_hash', 'review_hash', 'platform_role', 'owner_user_id'], ['prohibited']);
    }
}
