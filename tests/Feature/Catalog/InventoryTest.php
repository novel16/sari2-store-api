<?php

use App\Models\InventoryMovement;
use App\Services\Catalog\StockAdjustment;
use Illuminate\Support\Str;
use Tests\Support\CatalogTestHelpers;

uses(CatalogTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->base = '/api/v1/stores/'.$this->store->id;
    $this->actingAs($this->owner, 'web');
    $this->product = $this->createProduct($this->store);
    $this->adjustment = [
        'product_id' => $this->product->id, 'mode' => 'add', 'quantity' => 3,
        'reason' => 'New delivery', 'request_key' => (string) Str::uuid(),
    ];
});

it('audits additions removals and counted corrections with the real actor', function () {
    $this->postJson($this->base.'/inventory/adjustments', $this->adjustment)->assertCreated()
        ->assertJsonPath('data.quantity_before', 10)->assertJsonPath('data.quantity_after', 13)
        ->assertJsonPath('data.actor.id', $this->owner->id)->assertJsonPath('data.movement_type', 'stock_in');
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, [
        'mode' => 'remove', 'quantity' => 2, 'request_key' => (string) Str::uuid(),
    ]))->assertCreated()->assertJsonPath('data.quantity_delta', -2)->assertJsonPath('product.inventory.quantity_on_hand', 11);
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, [
        'mode' => 'set', 'quantity' => 0, 'expected_version' => 3, 'request_key' => (string) Str::uuid(),
    ]))->assertCreated()->assertJsonPath('data.quantity_after', 0)->assertJsonPath('data.movement_type', 'adjustment');
    $this->getJson($this->base.'/inventory/movements?product_id='.$this->product->id)->assertOk()
        ->assertJsonCount(4, 'data')->assertJsonPath('data.0.quantity_after', 0);
});

it('replays a successful adjustment without double changing stock even after later movements', function () {
    $this->postJson($this->base.'/inventory/adjustments', $this->adjustment)->assertCreated();
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, ['request_key' => (string) Str::uuid()]))->assertCreated();
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, ['request_key' => strtoupper($this->adjustment['request_key'])]))->assertOk()
        ->assertJsonPath('meta.replayed', true)->assertJsonPath('data.quantity_after', 13)
        ->assertJsonPath('product.inventory.quantity_on_hand', 16);
    $this->assertDatabaseCount('inventory_movements', 3);
});

it('rejects reusing an adjustment key with different details', function () {
    $this->postJson($this->base.'/inventory/adjustments', $this->adjustment)->assertCreated();
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, ['quantity' => 4]))->assertConflict();
    expect($this->product->inventory->quantity_on_hand)->toBe(13);
});

it('rejects invalid and negative stock without recording a movement', function (array $values) {
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, $values))->assertUnprocessable();
    expect($this->product->inventory->quantity_on_hand)->toBe(10);
    $this->assertDatabaseCount('inventory_movements', 1);
})->with([
    [['mode' => 'remove', 'quantity' => 11]], [['quantity' => 0]], [['quantity' => -1]], [['quantity' => 1.5]],
    [['quantity' => 1000000001]], [['reason' => '']], [['actor_user_id' => 999]], [['quantity_on_hand' => 999]],
]);

it('requires a version for counted stock and rejects stale counts', function () {
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, ['mode' => 'set']))
        ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
    $this->postJson($this->base.'/inventory/adjustments', $this->adjustment)->assertCreated();
    $this->postJson($this->base.'/inventory/adjustments', array_replace($this->adjustment, [
        'mode' => 'set', 'quantity' => 6, 'expected_version' => 1, 'request_key' => (string) Str::uuid(),
    ]))->assertConflict();
    expect($this->product->inventory->quantity_on_hand)->toBe(13);
});

it('blocks stock changes to archived products but preserves their history', function () {
    $this->deleteJson($this->base.'/products/'.$this->product->id)->assertOk();
    $this->postJson($this->base.'/inventory/adjustments', $this->adjustment)->assertConflict();
    $this->getJson($this->base.'/inventory/movements?product_id='.$this->product->id)->assertOk()->assertJsonCount(1, 'data');
});

it('returns authoritative inventory totals excluding archived products', function () {
    $this->createProduct($this->store, ['initial_stock' => 2, 'cost_centavos' => 125]);
    $this->createProduct($this->store, ['initial_stock' => 0]);
    $this->createProduct($this->store, ['status' => 'archived', 'initial_stock' => 99]);
    $this->getJson($this->base.'/inventory?stock=low')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.summary.total_units', 12)->assertJsonPath('meta.summary.low_stock', 1)
        ->assertJsonPath('meta.summary.out_of_stock', 1)->assertJsonPath('meta.summary.inventory_value_centavos', '10250');
});

it('rolls back stock if writing the movement fails', function () {
    InventoryMovement::creating(fn () => throw new RuntimeException('Simulated movement failure'));
    try {
        expect(fn () => app(StockAdjustment::class)->handle($this->store, $this->owner, $this->adjustment))->toThrow(RuntimeException::class);
    } finally {
        InventoryMovement::flushEventListeners();
    }
    expect($this->product->inventory->quantity_on_hand)->toBe(10)->and($this->product->inventory->version)->toBe(1);
    $this->assertDatabaseCount('inventory_movements', 1);
});
