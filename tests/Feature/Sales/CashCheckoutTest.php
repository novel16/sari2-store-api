<?php

use App\Models\Plan;
use App\Models\SalePayment;
use App\Services\Sales\CompleteSale;
use Illuminate\Support\Str;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->base = '/api/v1/stores/'.$this->store->id;
    $this->actingAs($this->owner, 'web');
    $this->product = $this->createProduct($this->store);
});

it('completes a cash sale with exact totals snapshots payment change and audited stock deduction', function () {
    $result = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()
        ->assertJsonPath('data.subtotal_centavos', 3000)->assertJsonPath('data.discount_centavos', 100)
        ->assertJsonPath('data.total_centavos', 2900)->assertJsonPath('data.amount_paid_centavos', 2900)
        ->assertJsonPath('data.payment.tendered_centavos', 5000)->assertJsonPath('data.payment.change_centavos', 2100)
        ->assertJsonPath('data.items.0.unit_cost_centavos', 1000)->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items_count', 1)->assertJsonPath('data.quantity_total', 2)
        ->assertJsonPath('data.sold_by.id', $this->owner->id)->assertJsonPath('meta.replayed', false);
    expect($result->json('data.sale_number'))->toMatch('/^S-\d{4}-\d{8,}$/');
    expect($this->product->inventory->quantity_on_hand)->toBe(8)->and($this->product->inventory->version)->toBe(2);
    $this->assertDatabaseHas('inventory_movements', [
        'product_id' => $this->product->id, 'sale_id' => $result->json('data.id'), 'actor_user_id' => $this->owner->id,
        'movement_type' => 'sale', 'quantity_delta' => -2, 'quantity_before' => 10, 'quantity_after' => 8,
    ]);
    $this->getJson($this->base.'/inventory/movements?product_id='.$this->product->id)->assertOk()->assertJsonPath('data.0.sale_id', $result->json('data.id'));
});

it('replays the same checkout after price and stock changes without selling twice', function () {
    $input = $this->checkoutData($this->product);
    $saleId = $this->postJson($this->base.'/sales', $input)->assertCreated()->json('data.id');
    $this->patchJson($this->base.'/products/'.$this->product->id, ['price_centavos' => 2200, 'name' => 'Renamed'])->assertOk();
    $this->deleteJson($this->base.'/products/'.$this->product->id)->assertOk();
    $input['idempotency_key'] = strtoupper($input['idempotency_key']);
    $this->postJson($this->base.'/sales', $input)->assertOk()->assertJsonPath('data.id', $saleId)
        ->assertJsonPath('meta.replayed', true)->assertJsonPath('data.items.0.unit_price_centavos', 1500);
    $this->getJson($this->base.'/sales/by-key/'.$input['idempotency_key'])->assertOk()->assertJsonPath('data.id', $saleId);
    expect($this->product->inventory->quantity_on_hand)->toBe(8);
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('sale_payments', 1);
    $this->assertDatabaseCount('inventory_movements', 2);
});

it('rejects a reused checkout key with different details', function () {
    $input = $this->checkoutData($this->product);
    $this->postJson($this->base.'/sales', $input)->assertCreated();
    $input['items'][0]['quantity'] = 3;
    $this->postJson($this->base.'/sales', $input)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $this->assertDatabaseCount('sales', 1);
});

it('canonicalizes line order for a repeated checkout', function () {
    $other = $this->createProduct($this->store);
    $input = $this->checkoutData($this->product);
    $input['items'][] = ['product_id' => $other->id, 'quantity' => 1];
    $this->postJson($this->base.'/sales', $input)->assertCreated();
    $input['items'] = array_reverse($input['items']);
    $this->postJson($this->base.'/sales', $input)->assertOk()->assertJsonPath('meta.replayed', true);
    $this->assertDatabaseCount('sale_items', 2);
});

it('rejects a stale expected price before charging or changing stock', function () {
    $input = $this->checkoutData($this->product);
    $this->product->update(['price_centavos' => 1800]);
    $this->postJson($this->base.'/sales', $input)->assertConflict()->assertJsonPath('code', 'price_changed');
    expect($this->product->inventory->quantity_on_hand)->toBe(10);
    $this->assertDatabaseCount('sales', 0);
});

it('uses current server prices when no expected price is supplied', function () {
    $input = $this->checkoutData($this->product);
    unset($input['items'][0]['expected_unit_price_centavos']);
    $this->product->update(['price_centavos' => 1800]);
    $this->postJson($this->base.'/sales', $input)->assertCreated()->assertJsonPath('data.subtotal_centavos', 3600);
});

it('rejects insufficient stock or an archived product without partial sale records', function (string $state) {
    if ($state === 'archived') {
        $this->product->update(['status' => 'archived']);
    } else {
        $this->product->inventory()->update(['quantity_on_hand' => 1]);
    }
    $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertConflict();
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('sale_items', 0);
    $this->assertDatabaseCount('sale_payments', 0);
    $this->assertDatabaseCount('inventory_movements', 1);
})->with(['archived', 'insufficient']);

it('deducts all products atomically and rejects an overselling second checkout', function () {
    $other = $this->createProduct($this->store, ['initial_stock' => 1]);
    $input = $this->checkoutData($this->product);
    $input['items'][] = ['product_id' => $other->id, 'quantity' => 1];
    $this->postJson($this->base.'/sales', $input)->assertCreated()->assertJsonPath('data.quantity_total', 3);
    $input['idempotency_key'] = (string) Str::uuid();
    $this->postJson($this->base.'/sales', $input)->assertConflict();
    expect($this->product->inventory->quantity_on_hand)->toBe(8)->and($other->inventory->quantity_on_hand)->toBe(0);
    $this->assertDatabaseCount('sales', 1);
});

it('rolls back every sale row and all stock when payment persistence fails and permits a safe retry', function () {
    $other = $this->createProduct($this->store);
    $input = $this->checkoutData($this->product);
    $input['items'][] = ['product_id' => $other->id, 'quantity' => 1];
    SalePayment::creating(fn () => throw new RuntimeException('Simulated payment failure'));
    try {
        expect(fn () => app(CompleteSale::class)->handle($this->store, $this->owner, $input))->toThrow(RuntimeException::class);
    } finally {
        SalePayment::flushEventListeners();
    }
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('sale_items', 0);
    $this->assertDatabaseCount('sale_payments', 0);
    $this->assertDatabaseCount('inventory_movements', 2);
    expect($this->product->inventory->quantity_on_hand)->toBe(10)->and($other->inventory->quantity_on_hand)->toBe(10);
    $this->postJson($this->base.'/sales', $input)->assertCreated();
});

it('retains historical prices costs product identity and store identity after edits', function () {
    $id = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()->json('data.id');
    $originalStore = $this->store->name;
    $this->product->update(['name' => 'Changed', 'price_centavos' => 9000, 'cost_centavos' => 7000, 'status' => 'archived']);
    $this->store->update(['name' => 'Renamed store']);
    $this->owner->update(['name' => 'Renamed owner']);
    $this->getJson($this->base.'/sales/'.$id)->assertOk()->assertJsonPath('data.items.0.product_name_snapshot', 'Bottled water')
        ->assertJsonPath('data.items.0.unit_price_centavos', 1500)->assertJsonPath('data.items.0.unit_cost_centavos', 1000)
        ->assertJsonPath('data.store.name', $originalStore)->assertJsonPath('data.sold_by.name', 'Maria Reyes')->assertJsonPath('data.total_centavos', 2900);
});

it('keeps legitimate sales available when an account is above a downgraded product allowance', function () {
    Plan::where('code', 'free')->update(['active_product_limit' => 0]);
    $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated();
});

it('supports zero-price and fully discounted sales without inventing cash revenue', function () {
    $input = $this->checkoutData($this->product, ['discount_centavos' => 3000, 'payment' => ['tendered_centavos' => 0]]);
    $this->postJson($this->base.'/sales', $input)->assertCreated()->assertJsonPath('data.total_centavos', 0)->assertJsonPath('data.payment.change_centavos', 0);
});

it('rejects malformed quantities client totals unsupported payment methods and duplicate product lines', function (array $override) {
    $input = array_replace_recursive($this->checkoutData($this->product), $override);
    if (isset($override['duplicate'])) {
        unset($input['duplicate']);
        $input['items'][] = $input['items'][0];
    }
    $this->postJson($this->base.'/sales', $input)->assertUnprocessable();
    $this->assertDatabaseCount('sales', 0);
    expect($this->product->inventory->quantity_on_hand)->toBe(10);
})->with([
    [['items' => [['quantity' => 0]]]], [['items' => [['quantity' => -1]]]], [['items' => [['quantity' => 1.5]]]],
    [['payment' => ['tendered_centavos' => 100]]], [['discount_centavos' => 3001]], [['discount_centavos' => -1]],
    [['payment' => ['method' => 'credit']]], [['payment' => ['method' => 'gcash_manual']]],
    [['total_centavos' => 1]], [['sold_by_user_id' => 999]], [['store_id' => 999]],
    [['items' => [['price_centavos' => 1]]]], [['duplicate' => true]], [['idempotency_key' => 'invalid']],
]);

it('bounds exact arithmetic before multiplying oversized line values', function () {
    $this->product->update(['price_centavos' => 1000000000]);
    $input = $this->checkoutData($this->product->fresh());
    $this->postJson($this->base.'/sales', $input)->assertUnprocessable()->assertJsonValidationErrors('items');
    $this->assertDatabaseCount('sales', 0);
});
