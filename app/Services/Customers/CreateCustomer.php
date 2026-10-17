<?php

namespace App\Services\Customers;

use App\Exceptions\CheckoutConflict;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateCustomer
{
    public function handle(Store $store, array $input): array
    {
        return DB::transaction(function () use ($store, $input): array {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $key = Str::lower($input['request_key']);
            $attributes = ['name' => $input['name'], 'phone' => $input['phone'] ?? null, 'notes' => $input['notes'] ?? null];
            $hash = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
            $previous = $store->customers()->where('request_key', $key)->first();
            if ($previous) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    throw new CheckoutConflict('customer_request_conflict', 'This customer request was already used with different details. Search the customer list before adding again.');
                }

                return ['customer' => $previous, 'replayed' => true];
            }

            return ['customer' => $store->customers()->create([...$attributes, 'request_key' => $key, 'request_hash' => $hash])->refresh(), 'replayed' => false];
        }, 3);
    }
}
