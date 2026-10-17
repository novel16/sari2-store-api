<?php

namespace Tests\Support;

use App\Models\Product;
use Illuminate\Support\Str;

trait SalesTestHelpers
{
    use CatalogTestHelpers;

    protected function checkoutData(Product $product, array $overrides = []): array
    {
        return array_replace_recursive([
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_unit_price_centavos' => $product->price_centavos]],
            'discount_centavos' => 100, 'payment' => ['method' => 'cash', 'tendered_centavos' => 5000],
        ], $overrides);
    }
}
