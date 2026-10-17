<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Catalog\CatalogRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends CatalogRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity,expected_unit_price_centavos'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'items.*.expected_unit_price_centavos' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'discount_centavos' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'payment' => ['required', 'array:method,tendered_centavos,customer_id'],
            'payment.method' => ['required', Rule::in(['cash', 'credit'])],
            'payment.tendered_centavos' => ['required_if:payment.method,cash', 'prohibited_unless:payment.method,cash', 'integer', 'min:0', 'max:1000000000'],
            'payment.customer_id' => ['required_if:payment.method,credit', 'prohibited_unless:payment.method,credit', 'integer', 'min:1'],
            'credit_amount_centavos' => ['prohibited'], 'customer_id' => ['prohibited'], 'payment_method' => ['prohibited'],
            ...array_fill_keys(['sold_by_user_id', 'sale_number', 'status', 'subtotal_centavos', 'total_centavos', 'amount_paid_centavos', 'change_centavos'], ['prohibited']),
            ...$this->protectedFields(),
        ];
    }
}
