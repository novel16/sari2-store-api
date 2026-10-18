<?php

namespace App\Services\Customers;

use App\Exceptions\CheckoutConflict;
use App\Models\CreditLedgerEntry;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordOpeningBalance
{
    public function handle(Store $store, Customer $customer, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $customer, $actor, $input) {
            $store = StoreWriteAccess::lock($store);
            $customer = $store->customers()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $key = Str::lower($input['request_key']);
            $amount = (int) $input['amount_centavos'];
            $notes = trim($input['notes']);
            $hash = hash('sha256', json_encode(['opening_balance', $actor->id, $customer->id, $amount, $notes], JSON_THROW_ON_ERROR));
            $entry = CreditLedgerEntry::where('store_id', $store->id)->where('request_key', $key)->first();
            $replayed = (bool) $entry;
            if ($entry) {
                if (! hash_equals($entry->request_hash, $hash)) {
                    throw new CheckoutConflict('opening_key_conflict', 'This request key was already used with different details. Check the customer ledger.');
                }
            } else {
                if ($customer->status !== 'active' || $customer->balance_centavos !== 0 || $customer->ledgerEntries()->exists()) {
                    throw new CheckoutConflict('opening_not_available', 'Opening utang is available only for an active customer with no financial history. Refresh the customer ledger.');
                }
                $entry = $customer->ledgerEntries()->create([
                    'store_id' => $store->id, 'actor_user_id' => $actor->id, 'entry_type' => 'opening_balance',
                    'request_key' => $key, 'request_hash' => $hash, 'amount_centavos' => $amount,
                    'balance_before_centavos' => 0, 'balance_after_centavos' => $amount, 'notes' => $notes,
                    'actor_name_snapshot' => $actor->name, 'customer_name_snapshot' => $customer->name,
                    'customer_phone_snapshot' => $customer->phone,
                ]);
                $customer->forceFill(['balance_centavos' => $amount])->save();
            }

            return ['entry' => $entry->load(['actor', 'sale']), 'customer' => $customer, 'replayed' => $replayed];
        }, 3);
    }
}
