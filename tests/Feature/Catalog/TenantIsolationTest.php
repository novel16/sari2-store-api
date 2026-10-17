<?php

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\CatalogTestHelpers;

uses(CatalogTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->other = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->foreign = $this->ownerStore($this->other);
    $this->actingAs($this->other, 'web');
    $this->category = $this->postJson('/api/v1/stores/'.$this->foreign->id.'/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
    $this->product = $this->createProduct($this->foreign, ['barcode' => '001234', 'sku' => 'WATER', 'category_id' => $this->category]);
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
});

it('allows identical barcodes SKUs and category names in different stores', function () {
    $this->postJson($this->base.'/categories', ['name' => 'Drinks'])->assertCreated();
    $this->createProduct($this->store, ['barcode' => '001234', 'sku' => 'WATER']);
    $this->getJson($this->base.'/products/lookup?barcode=001234')->assertOk()->assertJsonPath('data.store_id', $this->store->id);
    $this->getJson($this->base.'/products')->assertOk()->assertJsonCount(1, 'data');
});

it('cannot use a foreign store for catalog reads or writes', function (string $method, string $path) {
    $this->{$method}('/api/v1/stores/'.$this->foreign->id.$path, [])->assertNotFound();
})->with([
    ['getJson', '/products'], ['postJson', '/products'], ['getJson', '/categories'], ['postJson', '/categories'],
    ['getJson', '/inventory'], ['getJson', '/inventory/movements'], ['postJson', '/inventory/adjustments'],
]);

it('cannot read edit archive or reference a foreign product or category through an owned store', function () {
    $this->getJson($this->base.'/products/'.$this->product->id)->assertNotFound();
    $this->patchJson($this->base.'/products/'.$this->product->id, ['name' => 'Stolen'])->assertNotFound();
    $this->deleteJson($this->base.'/products/'.$this->product->id)->assertNotFound();
    $this->patchJson($this->base.'/categories/'.$this->category, ['name' => 'Stolen'])->assertNotFound();
    $this->deleteJson($this->base.'/categories/'.$this->category)->assertNotFound();
    $this->postJson($this->base.'/products', $this->productData(['category_id' => $this->category]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->getJson($this->base.'/products/lookup?barcode=001234')->assertNotFound();
    $this->getJson($this->base.'/inventory/movements?product_id='.$this->product->id)->assertNotFound();
    $this->postJson($this->base.'/inventory/adjustments', [
        'product_id' => $this->product->id, 'mode' => 'add', 'quantity' => 2, 'reason' => 'Invalid', 'request_key' => (string) Str::uuid(),
    ])->assertNotFound();
    expect($this->product->fresh()->name)->toBe('Bottled water')->and($this->product->inventory->quantity_on_hand)->toBe(10);
});

it('does not grant admins or guests access to private catalog endpoints', function () {
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->getJson($this->base.'/products')->assertUnauthorized();
    $admin = User::factory()->create();
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->actingAs($admin, 'web')->getJson($this->base.'/products')->assertNotFound();
});
