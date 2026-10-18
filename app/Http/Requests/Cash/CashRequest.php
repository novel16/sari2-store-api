<?php

namespace App\Http\Requests\Cash;

use App\Http\Requests\Catalog\CatalogRequest;

abstract class CashRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['notes', 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'notes' && $value === '' ? null : $value]);
            }
        }
    }

    protected function cashProtectedFields(array $allowed = []): array
    {
        return [...$this->protectedFields(), ...array_fill_keys(array_diff([
            'cash_day_id', 'business_date', 'status', 'opening_cash_centavos', 'counted_cash_centavos', 'variance_centavos',
            'totals', 'closing', 'closed_at', 'opened_at', 'opened_by_user_id', 'closed_by_user_id', 'actor_name_snapshot',
            'opened_by_name_snapshot', 'closed_by_name_snapshot', 'request_hash', 'closing_request_key', 'closing_request_hash',
            'amount_centavos', 'direction', 'reason', 'notes', 'review_token', 'created_at', 'updated_at',
        ], $allowed), ['prohibited'])];
    }
}
