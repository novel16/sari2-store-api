<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class CategoryRequest extends CatalogRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'required', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emoji' => ['sometimes', 'string', 'max:16'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            ...$this->protectedFields(),
        ];
    }
}
