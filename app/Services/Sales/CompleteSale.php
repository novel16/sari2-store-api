<?php

namespace App\Services\Sales;

use App\Exceptions\CheckoutConflict;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompleteSale
{
    public function handle(Store $store, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $actor, $input): array {
            // Share the same lock order with catalog edits and stock adjustments.
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $items = collect($input['items'])->map(fn ($item) => [
                'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'],
                'expected_price' => isset($item['expected_unit_price_centavos']) ? (int) $item['expected_unit_price_centavos'] : null,
            ])->sortBy('product_id')->values();
            $key = Str::lower($input['idempotency_key']);
            $discount = (int) ($input['discount_centavos'] ?? 0);
            $method = $input['payment']['method'];
            $tendered = $method === 'cash' ? (int) $input['payment']['tendered_centavos'] : 0;
            $customerId = $method === 'credit' ? (int) $input['payment']['customer_id'] : null;
            // Preserve the fingerprint of existing cash checkouts for safe old retries.
            $hash = hash('sha256', json_encode([$actor->id, $items->all(), $discount, $method, $method === 'cash' ? $tendered : $customerId], JSON_THROW_ON_ERROR));
            $previous = $store->sales()->where('idempotency_key', $key)->first();
            if ($previous) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    throw new CheckoutConflict('idempotency_conflict', 'This checkout was already used with different details. Check the saved sale before starting another.');
                }

                return $this->result($previous, true);
            }
            if ($items->pluck('product_id')->unique()->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => ['Each product must appear only once in the cart.']]);
            }
            $customer = $customerId ? $store->customers()->whereKey($customerId)->lockForUpdate()->firstOrFail() : null;
            if ($customer && $customer->status !== 'active') {
                throw new CheckoutConflict('customer_unavailable', 'This customer is unavailable. Choose an active customer before reviewing the sale again.');
            }
            // Lock all products, then all inventory rows in a stable order before processing lines.
            $products = $store->products()->whereIn('id', $items->pluck('product_id'))
                ->with(['inventory' => fn ($query) => $query->where('store_id', $store->id)->orderBy('product_id')->lockForUpdate()])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($products->count() === $items->count(), 404);
            $subtotal = 0;
            $lines = [];
            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                if ($product->status !== 'active') {
                    throw new CheckoutConflict('product_unavailable', "{$product->name} is archived. Review the cart before checking out.");
                }
                if ($item['expected_price'] !== null && $item['expected_price'] !== $product->price_centavos) {
                    throw new CheckoutConflict('price_changed', "The price of {$product->name} changed. Refresh and review the cart.");
                }
                $inventory = $product->inventory;
                abort_unless($inventory, 404);
                if ($inventory->quantity_on_hand < $item['quantity']) {
                    throw new CheckoutConflict('insufficient_stock', "Not enough stock for {$product->name}. Available: {$inventory->quantity_on_hand}.");
                }
                if ($product->price_centavos > 0 && $item['quantity'] > intdiv(1000000000 - $subtotal, $product->price_centavos)) {
                    throw ValidationException::withMessages(['items' => ['A sale subtotal cannot exceed ₱10,000,000.00.']]);
                }
                $lineTotal = $product->price_centavos * $item['quantity'];
                $subtotal += $lineTotal;
                $lines[] = compact('product', 'inventory', 'item', 'lineTotal');
            }
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount_centavos' => ['Discount cannot exceed the subtotal.']]);
            }
            $total = $subtotal - $discount;
            if ($method === 'cash' && $tendered < $total) {
                throw ValidationException::withMessages(['payment.tendered_centavos' => ['Cash received must cover the sale total.']]);
            }
            if ($method === 'credit' && ($total < 1 || $customer->balance_centavos > PHP_INT_MAX - $total)) {
                throw ValidationException::withMessages(['payment.customer_id' => ['Utang must be greater than zero and fit the customer balance. Use Cash for a fully discounted sale.']]);
            }
            $sale = $store->sales()->create([
                'sold_by_user_id' => $actor->id, 'sold_by_name_snapshot' => $actor->name,
                'idempotency_key' => $key, 'request_hash' => $hash, 'status' => 'completed',
                'store_name_snapshot' => $store->name, 'store_address_snapshot' => $store->address,
                'store_phone_snapshot' => $store->phone, 'subtotal_centavos' => $subtotal,
                'discount_centavos' => $discount, 'total_centavos' => $total, 'amount_paid_centavos' => $method === 'cash' ? $total : 0,
                'payment_method' => $method, 'customer_id' => $customer?->id,
                'customer_name_snapshot' => $customer?->name, 'customer_phone_snapshot' => $customer?->phone,
                'credit_amount_centavos' => $method === 'credit' ? $total : 0,
                'items_count' => $items->count(), 'quantity_total' => $items->sum('quantity'), 'completed_at' => now(),
            ]);
            $sale->forceFill(['sale_number' => 'S-'.now('Asia/Manila')->format('Y').'-'.str_pad((string) $sale->id, 8, '0', STR_PAD_LEFT)])->save();
            foreach ($lines as $line) {
                ['product' => $product, 'inventory' => $inventory, 'item' => $item, 'lineTotal' => $lineTotal] = $line;
                $sale->items()->create([
                    'store_id' => $store->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name,
                    'sku_snapshot' => $product->sku, 'barcode_snapshot' => $product->barcode, 'unit_snapshot' => $product->unit,
                    'quantity' => $item['quantity'], 'unit_price_centavos' => $product->price_centavos,
                    'unit_cost_centavos' => $product->cost_centavos, 'line_total_centavos' => $lineTotal,
                ]);
                $before = $inventory->quantity_on_hand;
                $after = $before - $item['quantity'];
                $inventory->forceFill(['quantity_on_hand' => $after, 'version' => $inventory->version + 1])->save();
                $product->movements()->create([
                    'store_id' => $store->id, 'actor_user_id' => $actor->id, 'sale_id' => $sale->id,
                    'movement_type' => 'sale', 'quantity_before' => $before, 'quantity_after' => $after,
                    'quantity_delta' => -$item['quantity'], 'version_after' => $inventory->version,
                    'reason' => ($method === 'cash' ? 'Cash sale ' : 'Utang sale ').$sale->sale_number,
                ]);
            }
            if ($method === 'cash') {
                $sale->payment()->create([
                    'store_id' => $store->id, 'method' => 'cash', 'amount_centavos' => $total,
                    'tendered_centavos' => $tendered, 'change_centavos' => $tendered - $total,
                ]);
            }
            if ($customer) {
                $before = $customer->balance_centavos;
                $customer->forceFill(['balance_centavos' => $before + $total])->save();
                $customer->ledgerEntries()->create([
                    'store_id' => $store->id, 'sale_id' => $sale->id, 'actor_user_id' => $actor->id,
                    'entry_type' => 'charge', 'amount_centavos' => $total,
                    'balance_before_centavos' => $before, 'balance_after_centavos' => $before + $total,
                ]);
            }

            return $this->result($sale->refresh(), false);
        }, 3);
    }

    private function result(Sale $sale, bool $replayed): array
    {
        return ['sale' => $sale->load(['items', 'payment', 'creditEntry']), 'replayed' => $replayed];
    }
}
