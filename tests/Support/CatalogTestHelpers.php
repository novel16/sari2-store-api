<?php

namespace Tests\Support;

use App\Models\Product;
use App\Models\Store;

trait CatalogTestHelpers
{
    use AuthTestHelpers;

    protected function productData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Bottled water', 'unit' => 'bottle', 'price_centavos' => 1500,
            'cost_centavos' => 1000, 'low_stock_threshold' => 5, 'initial_stock' => 10,
        ], $overrides);
    }

    protected function createProduct(Store $store, array $overrides = []): Product
    {
        $response = $this->postJson('/api/v1/stores/'.$store->id.'/products', $this->productData($overrides))->assertCreated();

        return Product::findOrFail($response->json('data.id'));
    }
}
