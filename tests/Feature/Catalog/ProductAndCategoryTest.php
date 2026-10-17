<?php

use App\Models\InventoryMovement;
use App\Services\Catalog\ProductWriter;
use Tests\Support\CatalogTestHelpers;

uses(CatalogTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->base = '/api/v1/stores/'.$this->store->id;
    $this->actingAs($this->owner, 'web');
});

it('creates edits archives and restores a category without losing its record', function () {
    $category = $this->postJson($this->base.'/categories', ['name' => 'Drinks', 'description' => 'Cold drinks'])
        ->assertCreated()->assertJsonPath('data.products_count', 0)->json('data.id');
    $this->patchJson($this->base.'/categories/'.$category, ['name' => 'Beverages'])->assertOk()->assertJsonPath('data.name', 'Beverages');
    $this->deleteJson($this->base.'/categories/'.$category)->assertOk()->assertJsonPath('data.status', 'archived');
    $this->getJson($this->base.'/categories')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->base.'/categories?status=archived')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson($this->base.'/categories/'.$category, ['status' => 'active'])->assertOk();
    $this->assertDatabaseCount('categories', 1);
});

it('keeps category names unique ignoring case and whitespace including archives', function () {
    $category = $this->postJson($this->base.'/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
    $this->postJson($this->base.'/categories', ['name' => ' drinks '])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->deleteJson($this->base.'/categories/'.$category)->assertOk();
    $this->postJson($this->base.'/categories', ['name' => 'DRINKS'])->assertUnprocessable();
    $this->patchJson($this->base.'/categories/'.$category, ['name' => 'DRINKS'])->assertOk();
});

it('requires reassignment of active products before archiving their category', function () {
    $category = $this->postJson($this->base.'/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
    $product = $this->createProduct($this->store, ['category_id' => $category]);
    $this->deleteJson($this->base.'/categories/'.$category)->assertConflict();
    $this->patchJson($this->base.'/products/'.$product->id, ['category_id' => null])->assertOk();
    $this->deleteJson($this->base.'/categories/'.$category)->assertOk();
});

it('persists exact centavos and creates audited opening stock including zero', function (int $opening) {
    $response = $this->postJson($this->base.'/products', $this->productData(['initial_stock' => $opening, 'price_centavos' => 1501]))
        ->assertCreated()->assertJsonPath('data.price_centavos', 1501)
        ->assertJsonPath('data.inventory.quantity_on_hand', $opening)->assertJsonPath('data.inventory.version', 1);
    $this->assertDatabaseHas('inventory_movements', [
        'store_id' => $this->store->id, 'product_id' => $response->json('data.id'),
        'actor_user_id' => $this->owner->id, 'movement_type' => 'opening',
        'quantity_before' => 0, 'quantity_after' => $opening, 'quantity_delta' => $opening,
    ]);
})->with([0, 10]);

it('preserves leading zeros and normalizes optional codes without globally reserving them', function () {
    $product = $this->createProduct($this->store, ['barcode' => '001234567890', 'sku' => 'water-01']);
    expect($product->barcode)->toBe('001234567890')->and($product->sku)->toBe('WATER-01');
    $this->getJson($this->base.'/products/lookup?barcode=001234567890')->assertOk()->assertJsonPath('data.id', $product->id);
    $this->createProduct($this->store, ['barcode' => null, 'sku' => null]);
    $this->createProduct($this->store, ['barcode' => null, 'sku' => null]);
    $this->postJson($this->base.'/products', $this->productData(['sku' => 'water-01']))->assertUnprocessable()->assertJsonValidationErrors('sku');
    $this->postJson($this->base.'/products', $this->productData(['barcode' => $product->barcode]))->assertUnprocessable()->assertJsonValidationErrors('barcode');
    $this->patchJson($this->base.'/products/'.$product->id, ['sku' => 'water-01', 'barcode' => $product->barcode])->assertOk();
    $this->deleteJson($this->base.'/products/'.$product->id)->assertOk();
    $this->getJson($this->base.'/products/lookup?barcode=001234567890')->assertNotFound();
    $this->postJson($this->base.'/products', $this->productData(['barcode' => $product->barcode]))->assertUnprocessable();
});

it('edits and archives products while retaining stock and movements', function () {
    $product = $this->createProduct($this->store);
    $this->patchJson($this->base.'/products/'.$product->id, ['name' => 'Mineral water', 'price_centavos' => 1750])->assertOk()
        ->assertJsonPath('data.inventory.quantity_on_hand', 10);
    $this->deleteJson($this->base.'/products/'.$product->id)->assertOk()->assertJsonPath('data.status', 'archived');
    $this->getJson($this->base.'/products')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->base.'/products?status=archived')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson($this->base.'/products/'.$product->id, ['status' => 'active'])->assertOk();
    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('inventory_movements', 1);
});

it('requires an active category when restoring a product', function () {
    $category = $this->postJson($this->base.'/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
    $product = $this->createProduct($this->store, ['category_id' => $category]);
    $this->deleteJson($this->base.'/products/'.$product->id)->assertOk();
    $this->deleteJson($this->base.'/categories/'.$category)->assertOk();
    $this->patchJson($this->base.'/products/'.$product->id, ['status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->patchJson($this->base.'/products/'.$product->id, ['category_id' => null, 'status' => 'active'])->assertOk();
});

it('filters and paginates products using server stock and category data', function () {
    $category = $this->postJson($this->base.'/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
    $this->createProduct($this->store, ['name' => 'Water', 'category_id' => $category, 'initial_stock' => 10]);
    $this->createProduct($this->store, ['name' => 'Cola', 'category_id' => $category, 'initial_stock' => 3]);
    $this->createProduct($this->store, ['name' => 'Juice', 'initial_stock' => 0]);
    $this->getJson($this->base.'/products?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    $this->getJson($this->base.'/products?stock=low')->assertOk()->assertJsonPath('data.0.name', 'Cola')->assertJsonCount(1, 'data');
    $this->getJson($this->base.'/products?stock=out')->assertOk()->assertJsonPath('data.0.name', 'Juice');
    $this->getJson($this->base.'/products?q=Drinks&category_id='.$category)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($this->base.'/products?q=missing')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->base.'/products?per_page=101')->assertUnprocessable();
});

it('rejects malformed product values and stock or tenant injection', function (array $fields) {
    $this->postJson($this->base.'/products', $this->productData($fields))->assertUnprocessable();
    $this->assertDatabaseCount('products', 0);
})->with([
    [['price_centavos' => 1.5]], [['cost_centavos' => -1]], [['initial_stock' => -1]], [['initial_stock' => 0.5]],
    [['low_stock_threshold' => -1]], [['barcode' => 123456]], [['store_id' => 999]], [['quantity_on_hand' => 500]],
]);

it('does not allow opening stock to overwrite an existing inventory', function () {
    $product = $this->createProduct($this->store);
    $this->patchJson($this->base.'/products/'.$product->id, ['initial_stock' => 100])->assertUnprocessable();
    expect($product->inventory->quantity_on_hand)->toBe(10);
});

it('rolls back product creation if its stock movement cannot be recorded', function () {
    InventoryMovement::creating(fn () => throw new RuntimeException('Simulated movement failure'));
    try {
        expect(fn () => app(ProductWriter::class)->save($this->store, $this->owner, $this->productData()))->toThrow(RuntimeException::class);
    } finally {
        InventoryMovement::flushEventListeners();
    }
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('inventories', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});
