<?php

namespace App\Services\Catalog;

use App\Models\InventoryMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockAdjustment
{
    public function handle(Store $store, User $actor, array $attributes): array
    {
        return DB::transaction(function () use ($store, $actor, $attributes): array {
            // Consistent lock order: store, product, inventory. Never trust a browser stock total.
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $requestKey = Str::lower($attributes['request_key']);
            $fingerprint = hash('sha256', json_encode([
                (int) $attributes['product_id'], $attributes['mode'], (int) $attributes['quantity'],
                $attributes['mode'] === 'set' ? (int) $attributes['expected_version'] : null, trim($attributes['reason']),
            ], JSON_THROW_ON_ERROR));
            $previous = $store->inventoryMovements()->where('request_key', $requestKey)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $fingerprint), 409,
                    'This adjustment key was already used with different details. Start a new adjustment.');

                return $this->result($previous, true);
            }

            $product = $store->products()->whereKey($attributes['product_id'])->lockForUpdate()->firstOrFail();
            abort_unless($product->status === 'active', 409, 'Restore this product before adjusting its stock.');
            $inventory = $product->inventory()->lockForUpdate()->firstOrFail();
            $before = $inventory->quantity_on_hand;
            $quantity = (int) $attributes['quantity'];
            if ($attributes['mode'] === 'set') {
                abort_unless($inventory->version === (int) $attributes['expected_version'], 409,
                    'Stock changed since you opened this form. Reload inventory and count again.');
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
