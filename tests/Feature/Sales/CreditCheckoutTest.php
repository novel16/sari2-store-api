<?php

use App\Models\CreditLedgerEntry;
use App\Services\Sales\CompleteSale;
use Illuminate\Support\Str;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
    $this->product = $this->createProduct($this->store);
    $this->customer = $this->store->customers()->create(['name' => 'Aling Linda', 'phone' => '09123456789', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
    $this->input = $this->checkoutData($this->product);
    $this->input['payment'] = ['method' => 'credit', 'customer_id' => $this->customer->id];
});

it('saves utang stock and a single customer charge atomically without recording cash', function () {
    $sale = $this->postJson($this->base.'/sales', $this->input)->assertCreated()
        ->assertJsonPath('data.payment_method', 'credit')->assertJsonPath('data.payment', null)
        ->assertJsonPath('data.customer.name', 'Aling Linda')->assertJsonPath('data.total_centavos', 2900)
        ->assertJsonPath('data.credit_amount_centavos', 2900)->assertJsonPath('data.amount_paid_centavos', 0)
        ->assertJsonPath('data.credit.balance_before_centavos', '0')->assertJsonPath('data.credit.balance_after_centavos', '2900')->json('data.id');
    expect($this->customer->fresh()->balance_centavos)->toBe(2900)->and($this->product->inventory->quantity_on_hand)->toBe(8);
    $this->assertDatabaseCount('sale_payments', 0);
    $this->assertDatabaseHas('credit_ledger_entries', ['sale_id' => $sale, 'customer_id' => $this->customer->id, 'entry_type' => 'charge', 'amount_centavos' => 2900]);
    $this->getJson($this->base.'/sales')->assertOk()->assertJsonPath('meta.summary.net_sales_centavos', '2900')->assertJsonPath('meta.summary.cash_collected_centavos', '0');
    $this->getJson($this->base.'/customers/'.$this->customer->id.'/ledger')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('customer.balance_centavos', '2900')->assertJsonPath('data.0.sale_id', $sale);
});

it('replays an utang checkout after customer and catalog changes without a second charge', function () {
    $sale = $this->postJson($this->base.'/sales', $this->input)->assertCreated()->json('data.id');
    $this->customer->update(['name' => 'Renamed', 'status' => 'archived']);
    $this->product->update(['price_centavos' => 9000, 'status' => 'archived']);
    $this->input['idempotency_key'] = strtoupper($this->input['idempotency_key']);
    $this->postJson($this->base.'/sales', $this->input)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $sale)->assertJsonPath('data.customer.name', 'Aling Linda');
    $this->getJson($this->base.'/sales/by-key/'.$this->input['idempotency_key'])->assertOk()->assertJsonPath('data.credit.balance_after_centavos', '2900');
    expect($this->customer->fresh()->balance_centavos)->toBe(2900);
    $this->assertDatabaseCount('credit_ledger_entries', 1);
});

it('rejects changing the customer or payment method when reusing a checkout key', function () {
    $this->postJson($this->base.'/sales', $this->input)->assertCreated();
    $second = $this->store->customers()->create(['name' => 'Other customer', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64)]);
    $next = $this->input;
    $next['payment']['customer_id'] = $second->id;
    $this->postJson($this->base.'/sales', $next)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $next['payment'] = ['method' => 'cash', 'tendered_centavos' => 5000];
    $this->postJson($this->base.'/sales', $next)->assertConflict();
    expect($second->fresh()->balance_centavos)->toBe(0);
});

it('rolls back inventory sales and balances if the credit ledger write fails', function () {
    CreditLedgerEntry::creating(fn () => throw new RuntimeException('Simulated ledger failure'));
    try {
        expect(fn () => app(CompleteSale::class)->handle($this->store, $this->owner, $this->input))->toThrow(RuntimeException::class);
    } finally {
        CreditLedgerEntry::flushEventListeners();
    }
    expect($this->customer->fresh()->balance_centavos)->toBe(0)->and($this->product->inventory->quantity_on_hand)->toBe(10);
    foreach (['sales', 'sale_items', 'sale_payments', 'credit_ledger_entries'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->postJson($this->base.'/sales', $this->input)->assertCreated();
});

it('retains exact balances beyond the JavaScript safe integer limit', function () {
    $this->customer->forceFill(['balance_centavos' => 9007199254740993])->save();
    $this->postJson($this->base.'/sales', $this->input)->assertCreated()->assertJsonPath('data.credit.balance_after_centavos', '9007199254743893');
    $this->getJson($this->base.'/customers')->assertOk()->assertJsonPath('data.0.balance_centavos', '9007199254743893');
});

it('keeps cash checkout fingerprints compatible and does not alter customer balances', function () {
    $input = $this->checkoutData($this->product);
    $id = $this->postJson($this->base.'/sales', $input)->assertCreated()->json('data.id');
    $expected = hash('sha256', json_encode([$this->owner->id, [['product_id' => $this->product->id, 'quantity' => 2, 'expected_price' => 1500]], 100, 'cash', 5000], JSON_THROW_ON_ERROR));
    $this->assertDatabaseHas('sales', ['id' => $id, 'request_hash' => $expected, 'payment_method' => 'cash', 'credit_amount_centavos' => 0]);
    expect($this->customer->fresh()->balance_centavos)->toBe(0);
    $this->assertDatabaseCount('credit_ledger_entries', 0);
});

it('rejects unavailable customers and all invalid mixed cash and credit payloads', function (string $case) {
    if ($case === 'archived') {
        $this->customer->update(['status' => 'archived']);
    }
    if ($case === 'missing') {
        unset($this->input['payment']['customer_id']);
    }
    if ($case === 'foreign') {
        $this->input['payment']['customer_id'] = 999999;
    }
    if ($case === 'cash_tendered') {
        $this->input['payment']['tendered_centavos'] = 5000;
    }
    if ($case === 'cash_customer') {
        $this->input['payment'] = ['method' => 'cash', 'customer_id' => $this->customer->id, 'tendered_centavos' => 5000];
    }
    if ($case === 'zero_total') {
        $this->input['discount_centavos'] = 3000;
    }
    if ($case === 'spoofed_credit') {
        $this->input['credit_amount_centavos'] = 1;
    }
    $response = $this->postJson($this->base.'/sales', $this->input);
    if ($case === 'archived') {
        $response->assertConflict();
    } elseif ($case === 'foreign') {
        $response->assertNotFound();
    } else {
        $response->assertUnprocessable();
    }
    $this->assertDatabaseCount('sales', 0);
    expect($this->customer->fresh()->balance_centavos)->toBe(0)->and($this->product->inventory->quantity_on_hand)->toBe(10);
})->with(['archived', 'missing', 'foreign', 'cash_tendered', 'cash_customer', 'zero_total', 'spoofed_credit']);
