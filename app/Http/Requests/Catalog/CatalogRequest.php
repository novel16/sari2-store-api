<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

abstract class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication and store ownership are required by the route middleware.
        return true;
    }

    protected function protectedFields(): array
    {
        return array_fill_keys([
            'id', 'store_id', 'account_id', 'actor_user_id', 'user_id', 'inventory',
            'quantity_on_hand', 'stock', 'quantity_delta', 'quantity_before', 'quantity_after',
        ], ['prohibited']);
    }
}
