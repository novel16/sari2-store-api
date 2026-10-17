<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'store_id' => $this->store_id, 'sale_number' => $this->sale_number,
            'status' => $this->reversal ? ($this->reversal->kind === 'void' ? 'voided' : 'refunded') : $this->status,
            'reversal' => $this->reversal ? new SaleReversalResource($this->reversal) : null,
            'completed_at' => $this->completed_at,
            'payment_method' => $this->payment_method, 'credit_amount_centavos' => $this->credit_amount_centavos,
            'customer' => $this->customer_id ? ['id' => $this->customer_id, 'name' => $this->customer_name_snapshot, 'phone' => $this->customer_phone_snapshot] : null,
            'credit' => $this->whenLoaded('creditEntry', fn () => $this->creditEntry ? ['balance_before_centavos' => (string) $this->creditEntry->balance_before_centavos, 'balance_after_centavos' => (string) $this->creditEntry->balance_after_centavos] : null),
            'store' => ['name' => $this->store_name_snapshot, 'address' => $this->store_address_snapshot, 'phone' => $this->store_phone_snapshot],
            'sold_by' => ['id' => $this->sold_by_user_id, 'name' => $this->sold_by_name_snapshot],
            'subtotal_centavos' => $this->subtotal_centavos, 'discount_centavos' => $this->discount_centavos,
            'total_centavos' => $this->total_centavos, 'amount_paid_centavos' => $this->amount_paid_centavos,
            'items_count' => $this->items_count, 'quantity_total' => $this->quantity_total,
            'payment' => $this->whenLoaded('payment', fn () => $this->payment?->only(['method', 'amount_centavos', 'tendered_centavos', 'change_centavos'])),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => $item->only([
                'id', 'product_id', 'product_name_snapshot', 'sku_snapshot', 'barcode_snapshot', 'unit_snapshot',
                'quantity', 'unit_price_centavos', 'unit_cost_centavos', 'line_total_centavos',
            ]))),
        ];
    }
}
