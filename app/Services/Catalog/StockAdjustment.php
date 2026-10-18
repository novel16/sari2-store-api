<?php

namespace App\Services\Catalog;

use App\Exceptions\CheckoutConflict;
use App\Models\InventoryMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockAdjustment
{
    public function handle(Store $store, User $actor, array $attributes): array
    {
        return DB::transaction(function () use ($store, $actor, $attributes): array {
            // Lock account/store before product/inventory; never trust a browser stock total.
            $store = StoreWriteAccess::lock($store);
            $requestKey = Str::lower($attributes['request_key']);
            $fingerprint = hash('sha256', json_encode([
                (int) $attributes['product_id'], $attributes['mode'], (int) $attributes['quantity'],
                $attributes['mode'] === 'set' ? (int) $attributes['expected_version'] : null, trim($attributes['reason']),
            ], JSON_THROW_ON_ERROR));
            $previous = $store->inventoryMovements()->where('request_key', $requestKey)->first();
            if ($previous) {
                if (! hash_equals($previous->request_hash, $fingerprint)) {
                    throw new CheckoutConflict('adjustment_key_conflict',
                        'This adjustment key was already used with different details. Review the saved movement before adjusting again.');
                }

                return $this->result($previous, true);
            }

            $product = $store->products()->whereKey($attributes['product_id'])->lockForUpdate()->firstOrFail();
            if ($product->status !== 'active') {
                throw new CheckoutConflict('product_archived', 'Restore this product before adjusting its stock.');
            }
            $inventory = $product->inventory()->lockForUpdate()->firstOrFail();
            $before = $inventory->quantity_on_hand;
            $quantity = (int) $attributes['quantity'];
            if ($attributes['mode'] === 'set') {
                if ($inventory->version !== (int) $attributes['expected_version']) {
                    throw new CheckoutConflict('stock_changed', 'Stock changed since you opened this form. Reload inventory and count again.');
                }
                $after = $quantity;
            } else {
                $after = $before + ($attributes['mode'] === 'remove' ? -$quantity : $quantity);
            }
            if ($after < 0 || $after > 1000000000 || $after === $before) {
                throw ValidationException::withMessages(['quantity' => ['Choose a quantity that changes stock and keeps it between 0 and 1,000,000,000.']]);
            }
            $inventory->forceFill(['quantity_on_hand' => $after, 'version' => $inventory->version + 1])->save();
            $movement = $product->movements()->create([
                'store_id' => $store->id, 'actor_user_id' => $actor->id,
                'movement_type' => ['add' => 'stock_in', 'remove' => 'stock_out', 'set' => 'adjustment'][$attributes['mode']],
                'quantity_before' => $before, 'quantity_after' => $after, 'quantity_delta' => $after - $before,
                'version_after' => $inventory->version, 'reason' => trim($attributes['reason']),
                'request_key' => $requestKey, 'request_hash' => $fingerprint,
            ]);

            return $this->result($movement, false);
        }, 3);
    }

    private function result(InventoryMovement $movement, bool $replayed): array
    {
        return ['movement' => $movement->load(['product.category', 'product.inventory', 'actor']), 'replayed' => $replayed];
    }
}
