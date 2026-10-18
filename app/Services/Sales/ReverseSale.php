<?php

namespace App\Services\Sales;

use App\Exceptions\CheckoutConflict;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleReversal;
use App\Models\Store;
use App\Models\User;
use App\Services\Cash\CashDayGuard;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReverseSale
{
    public function preview(Store $store, Sale $sale): array
    {
        return DB::transaction(function () use ($store, $sale) {
            $sale = $store->sales()->with(['items', 'reversal'])->findOrFail($sale->id);
            $this->available($sale);
            $customer = $sale->customer_id ? $store->customers()->findOrFail($sale->customer_id) : null;

            return [...$this->amounts($sale, $customer), 'sale_id' => $sale->id, 'sale_number' => $sale->sale_number,
                'customer' => $customer ? ['name' => $customer->name] : null,
                'items' => $sale->items->map(fn ($item) => ['product_id' => $item->product_id,
                    'name' => $item->product_name_snapshot, 'quantity' => $item->quantity])->all()];
        });
    }

    public function handle(Store $store, Sale $sale, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $sale, $actor, $input) {
            $store = StoreWriteAccess::lock($store);
            $occurredAt = CashDayGuard::capture();
            $sale = $store->sales()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $key = Str::lower($input['request_key']);
            $hash = hash('sha256', json_encode([$actor->id, $sale->id, $input['kind'], trim($input['reason']),
                (bool) $input['restock'], $input['expected_balance_centavos'], (bool) $input['cash_confirmed']], JSON_THROW_ON_ERROR));
            $previous = SaleReversal::where('store_id', $store->id)->where('request_key', $key)->first();
            if ($previous) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    throw new CheckoutConflict('reversal_key_conflict', 'This reversal key was used with different details. Check the saved reversal.');
                }

                return $this->result($previous, true);
            }
            $this->available($sale);
            $customer = $sale->customer_id ? $store->customers()->whereKey($sale->customer_id)->lockForUpdate()->firstOrFail() : null;
            $amounts = $this->amounts($sale, $customer);
            if ($amounts['cash_refund_centavos'] > 0) {
                CashDayGuard::ensureCashAllowed($store, $occurredAt);
            }
            if ($amounts['balance_before_centavos'] !== $input['expected_balance_centavos']) {
                throw new CheckoutConflict('balance_changed', 'The customer balance changed. Review the cash refund and utang adjustment again.');
            }
            if ($amounts['cash_refund_centavos'] > 0 && ! $input['cash_confirmed']) {
                throw ValidationException::withMessages(['cash_confirmed' => ['Confirm the cash refund before saving.']]);
            }
            $reversal = SaleReversal::create([
                'store_id' => $store->id, 'sale_id' => $sale->id, 'actor_user_id' => $actor->id,
                'actor_name_snapshot' => $actor->name, 'request_key' => $key, 'request_hash' => $hash,
                'kind' => $input['kind'], 'reason' => trim($input['reason']), 'restock' => (bool) $input['restock'], ...$amounts,
                'created_at' => $occurredAt,
            ]);
            if ($input['restock']) {
                $items = $sale->items()->reorder('product_id')->orderBy('id')->get();
                $productIds = $items->pluck('product_id')->unique();
                // Match checkout's batch lock order and keep every read scoped to this store.
                $products = $store->products()->whereIn('id', $productIds)
                    ->with(['inventory' => fn ($query) => $query->where('store_id', $store->id)->orderBy('product_id')->lockForUpdate()])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                abort_unless($products->count() === $productIds->count(), 404);

                foreach ($items as $item) {
                    $product = $products[$item->product_id];
                    if ($product->unit !== $item->unit_snapshot) {
                        throw ValidationException::withMessages(['restock' => ['A product unit differs from the original sale. Choose no restock and reconcile the returned stock separately.']]);
                    }
                    $inventory = $product->inventory;
                    abort_unless($inventory, 404);
                    $before = $inventory->quantity_on_hand;
                    $after = $before + $item->quantity;
                    if ($after > 1000000000) {
                        throw new CheckoutConflict('stock_capacity', 'Returned stock would exceed the inventory limit. Review stock or choose no restock.');
                    }
                    $inventory->forceFill(['quantity_on_hand' => $after, 'version' => $inventory->version + 1])->save();
                    $product->movements()->create([
                        'store_id' => $store->id, 'actor_user_id' => $actor->id, 'sale_reversal_id' => $reversal->id,
                        'movement_type' => 'sale_return', 'quantity_delta' => $item->quantity,
                        'quantity_before' => $before, 'quantity_after' => $after, 'version_after' => $inventory->version,
                        'reason' => Str::substr(strtoupper($input['kind']).' '.$sale->sale_number.': '.trim($input['reason']), 0, 255),
                    ]);
                }
            }
            if ($customer && $amounts['credit_reversed_centavos'] > 0) {
                $customer->forceFill(['balance_centavos' => $amounts['balance_after_centavos']])->save();
                $customer->ledgerEntries()->create([
                    'store_id' => $store->id, 'sale_reversal_id' => $reversal->id, 'actor_user_id' => $actor->id,
                    'entry_type' => 'reversal', 'amount_centavos' => $amounts['credit_reversed_centavos'],
                    'balance_before_centavos' => $amounts['balance_before_centavos'], 'balance_after_centavos' => $amounts['balance_after_centavos'],
                    'notes' => trim($input['reason']), 'actor_name_snapshot' => $actor->name,
                    'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => $customer->phone,
                    'created_at' => $occurredAt,
                ]);
            }

            return $this->result($reversal, false);
        }, 3);
    }

    private function available(Sale $sale): void
    {
        if ($sale->status !== 'completed' || $sale->reversal()->exists()) {
            throw new CheckoutConflict('already_reversed', 'This sale has already been voided or refunded. Check its saved history.');
        }
    }

    private function amounts(Sale $sale, ?Customer $customer): array
    {
        $credit = $customer ? min($sale->total_centavos, $customer->balance_centavos) : 0;

        return ['total_centavos' => $sale->total_centavos, 'credit_reversed_centavos' => $credit,
            'cash_refund_centavos' => $sale->total_centavos - $credit,
            'balance_before_centavos' => $customer ? (string) $customer->balance_centavos : null,
            'balance_after_centavos' => $customer ? (string) ($customer->balance_centavos - $credit) : null];
    }

    private function result(SaleReversal $reversal, bool $replayed): array
    {
        return ['reversal' => $reversal, 'sale' => $reversal->sale()->with(['items', 'payment', 'creditEntry', 'reversal'])->firstOrFail(), 'replayed' => $replayed];
    }
}
