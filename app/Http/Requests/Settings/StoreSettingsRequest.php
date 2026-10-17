<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Catalog\CatalogRequest;

class StoreSettingsRequest extends CatalogRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['present', 'nullable', 'string', 'max:255'],
            'phone' => ['present', 'nullable', 'string', 'max:30'],
            'status' => ['prohibited'], 'slug' => ['prohibited'], 'owner_user_id' => ['prohibited'],
            ...$this->protectedFields(),
        ];
    }
}
