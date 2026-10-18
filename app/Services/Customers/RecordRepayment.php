<?php

namespace App\Services\Customers;

use App\Exceptions\CheckoutConflict;
use App\Models\CreditLedgerEntry;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\Cash\CashDayGuard;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordRepayment
{
    public function handle(Store $store, Customer $customer, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $customer, $actor, $input): array {
            // Match credit checkout's lock order to serialize charges and repayments.
            $store = StoreWriteAccess::lock($store);
            $occurredAt = CashDayGuard::capture();
            $customer = $store->customers()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $key = Str::lower($input['request_key']);
            $amount = (int) $input['amount_centavos'];
            $notes = isset($input['notes']) ? trim($input['notes']) : null;
            $notes = $notes === '' ? null : $notes;
            $hash = hash('sha256', json_encode([$actor->id, $customer->id, 'cash', $amount, $input['expected_balance_centavos'], $notes], JSON_THROW_ON_ERROR));
            $previous = CreditLedgerEntry::where('store_id', $store->id)->where('request_key', $key)->first();
            if ($previous) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    throw new CheckoutConflict('repayment_key_conflict', 'This payment request was already used with different details. Check the saved payment before starting another.');
                }

                return $this->result($previous, $customer, true);
            }
            CashDayGuard::ensureCashAllowed($store, $occurredAt);
            $before = $customer->balance_centavos;
            if ((string) $before !== $input['expected_balance_centavos']) {
                throw new CheckoutConflict('balance_changed', 'The customer balance changed. Review the current balance and confirm the payment again.');
            }
            if ($amount < 1 || $amount > 1000000000 || $amount > $before) {
                throw ValidationException::withMessages(['amount_centavos' => ['Enter a payment greater than zero that does not exceed the outstanding balance or PHP 10,000,000.00.']]);
            }
            $after = $before - $amount;
            $customer->forceFill(['balance_centavos' => $after])->save();
            $entry = $customer->ledgerEntries()->create([
                'store_id' => $store->id, 'actor_user_id' => $actor->id, 'entry_type' => 'payment',
                'amount_centavos' => $amount, 'balance_before_centavos' => $before, 'balance_after_centavos' => $after,
                'request_key' => $key, 'request_hash' => $hash, 'payment_method' => 'cash', 'notes' => $notes,
                'actor_name_snapshot' => $actor->name, 'customer_name_snapshot' => $customer->name,
                'customer_phone_snapshot' => $customer->phone,
                'created_at' => $occurredAt,
            ]);

            return $this->result($entry, $customer, false);
        }, 3);
    }

    private function result(CreditLedgerEntry $entry, Customer $customer, bool $replayed): array
    {
        return ['entry' => $entry->load(['sale', 'actor']), 'customer' => $customer, 'replayed' => $replayed];
    }
}
