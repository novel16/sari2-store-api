<?php

use App\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    $this->sale = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()->json('data');
    $this->path = $this->base.'/sales/'.$this->sale['id'].'/reversals';
    $this->input = ['request_key' => (string) Str::uuid(), 'kind' => 'refund', 'reason' => 'Returned unopened', 'restock' => true, 'expected_balance_centavos' => null, 'cash_confirmed' => true];
});
afterEach(fn () => Carbon::setTestNow());

it('reverses exactly once while preserving original sale payment and stock history', function () {
    $this->getJson($this->base.'/sales/'.$this->sale['id'].'/reversal-preview')->assertOk()->assertJsonPath('data.cash_refund_centavos', 2900);
    $this->postJson($this->path, $this->input)->assertCreated()->assertJsonPath('data.status', 'refunded')->assertJsonPath('reversal.cash_refund_centavos', 2900);
    $this->postJson($this->path, $this->input)->assertOk()->assertJsonPath('meta.replayed', true);
    $this->postJson($this->path, [...$this->input, 'request_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'already_reversed');
    $this->postJson($this->path, [...$this->input, 'reason' => 'Changed reason'])->assertConflict()->assertJsonPath('code', 'reversal_key_conflict');
    $this->getJson($this->base.'/sale-reversals/by-key/'.$this->input['request_key'])->assertOk()->assertJsonPath('data.status', 'refunded');
    expect($this->product->inventory()->first()->quantity_on_hand)->toBe(10);
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('sale_payments', 1);
    $this->assertDatabaseCount('sale_reversals', 1);
    $this->assertDatabaseHas('sales', ['id' => $this->sale['id'], 'status' => 'completed', 'total_centavos' => 2900]);
    $this->assertDatabaseHas('inventory_movements', ['movement_type' => 'sale', 'quantity_delta' => -2]);
    $this->assertDatabaseHas('inventory_movements', ['movement_type' => 'sale_return', 'quantity_delta' => 2]);
});

it('supports void and no-restock including archived products', function () {
    $this->product->update(['status' => 'archived']);
    $this->postJson($this->path, [...$this->input, 'kind' => 'void', 'restock' => false])->assertCreated()->assertJsonPath('data.status', 'voided');
    expect($this->product->inventory()->first()->quantity_on_hand)->toBe(8);
});

it('rolls back every reversal change if stock cannot be restored', function () {
    $this->product->inventory()->update(['quantity_on_hand' => 1000000000]);
    $this->postJson($this->path, $this->input)->assertConflict()->assertJsonPath('code', 'stock_capacity');
    $this->assertDatabaseCount('sale_reversals', 0);
    $this->assertDatabaseMissing('inventory_movements', ['movement_type' => 'sale_return']);
    expect($this->product->inventory()->first()->quantity_on_hand)->toBe(1000000000);
});

it('reverses pooled customer debt first and pays only the excess as cash', function ($extraCharge, $paid, $expectedCredit, $expectedCash, $expectedBalance) {
    $id = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Customer'])->assertCreated()->json('data.id');
    $credit = $this->checkoutData($this->product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $id];
    $sale = $this->postJson($this->base.'/sales', $credit)->assertCreated()->json('data');
    if ($extraCharge) {
        $this->postJson($this->base.'/sales', [...$credit, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    }
    $before = 2900 * ($extraCharge ? 2 : 1);
    if ($paid) {
        $this->postJson($this->base.'/customers/'.$id.'/payments', ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => $paid, 'expected_balance_centavos' => (string) $before])->assertCreated();
    }
    $balance = $before - $paid;
    $this->postJson($this->base.'/sales/'.$sale['id'].'/reversals', [...$this->input, 'expected_balance_centavos' => (string) $balance])->assertCreated()
        ->assertJsonPath('reversal.credit_reversed_centavos', $expectedCredit)->assertJsonPath('reversal.cash_refund_centavos', $expectedCash);
    expect($this->store->customers()->findOrFail($id)->balance_centavos)->toBe($expectedBalance);
    $this->assertDatabaseHas('credit_ledger_entries', ['sale_id' => $sale['id'], 'entry_type' => 'charge', 'amount_centavos' => 2900]);
})->with([[false, 0, 2900, 0, 0], [false, 1000, 1900, 1000, 0], [false, 2900, 0, 2900, 0], [true, 1000, 2900, 0, 1900]]);

it('requires another review when customer debt changed', function () {
    $this->postJson($this->path, [...$this->input, 'expected_balance_centavos' => '1'])->assertConflict()->assertJsonPath('code', 'balance_changed');
    $this->assertDatabaseCount('sale_reversals', 0);
});

it('records offsets on the reversal date and preserves historical report periods', function () {
    $sale = DB::table('sales')->where('id', $this->sale['id']);
    $sale->update(['completed_at' => '2026-10-15 03:00:00']);
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));
    $this->postJson($this->path, $this->input)->assertCreated();
    $this->getJson($this->base.'/reports/sales?date_from=2026-10-15&date_to=2026-10-15')->assertOk()->assertJsonPath('data.summary.net_sales_centavos', '2900')->assertJsonPath('data.summary.reversed_sales_centavos', '0');
    $this->getJson($this->base.'/reports/sales?date_from=2026-10-16&date_to=2026-10-16')->assertOk()->assertJsonPath('data.summary.net_after_reversals_centavos', '-2900')->assertJsonPath('data.summary.net_cash_collected_centavos', '-2900')->assertJsonPath('data.days.0.reversal_count', 1);
    $this->getJson($this->base.'/sales')->assertOk()->assertJsonPath('data.0.status', 'refunded')->assertJsonPath('meta.summary.transactions', 0);
});

it('keeps actor reason and amounts immutable after profile and catalog changes', function () {
    $this->postJson($this->path, $this->input)->assertCreated();
    $this->owner->update(['name' => 'Changed owner']);
    $this->product->update(['price_centavos' => 9999]);
    $this->getJson($this->base.'/sales/'.$this->sale['id'])->assertOk()->assertJsonPath('data.reversal.recorded_by.name', 'Maria Reyes')->assertJsonPath('data.total_centavos', 2900);
});

it('rejects malformed injected unconfirmed and unsupported reversals', function ($override) {
    $this->postJson($this->path, [...$this->input, ...$override])->assertUnprocessable();
    $this->assertDatabaseCount('sale_reversals', 0);
})->with(array_map(fn ($input) => [$input], [['kind' => 'partial'], ['reason' => ''], ['request_key' => 'bad'], ['restock' => 'invalid'], ['cash_confirmed' => false], ['cash_refund_centavos' => 999], ['store_id' => 99], ['expected_balance_centavos' => 1]]));

it('forbids guest access and foreign sale guesses', function () {
    $this->postJson($this->base.'/sales/999999/reversals', $this->input)->assertNotFound();
    $this->getJson($this->base.'/sale-reversals/by-key/'.Str::uuid())->assertNotFound();
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->postJson($this->path, $this->input)->assertUnauthorized();
});

it('rejects cross-store sale ids and does not grant platform admins private reversals', function () {
    $originalPath = $this->path;
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $other = $this->createOwner();
    $foreign = $this->ownerStore($other);
    $this->actingAs($other, 'web');
    $this->postJson($originalPath, $this->input)->assertNotFound();
    $this->getJson($this->base.'/sales/'.$this->sale['id'].'/reversal-preview')->assertNotFound();
    $this->postJson('/api/v1/stores/'.$foreign->id.'/sales/'.$this->sale['id'].'/reversals', $this->input)->assertNotFound();
    $this->resetBrowserGuards();
    $admin = User::factory()->create();
    $admin->forceFill(['status' => 'active', 'platform_role' => 'platform_admin'])->save();
    $this->actingAs($admin, 'web')->postJson($originalPath, $this->input)->assertNotFound();
});

it('rejects a genuinely stale credit preview after another repayment', function () {
    $id = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Suki'])->assertCreated()->json('data.id');
    $credit = $this->checkoutData($this->product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $id];
    $sale = $this->postJson($this->base.'/sales', $credit)->assertCreated()->json('data');
    $this->getJson($this->base.'/sales/'.$sale['id'].'/reversal-preview')->assertOk()->assertJsonPath('data.balance_before_centavos', '2900');
    $this->postJson($this->base.'/customers/'.$id.'/payments', ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 500, 'expected_balance_centavos' => '2900'])->assertCreated();
    $this->postJson($this->base.'/sales/'.$sale['id'].'/reversals', [...$this->input, 'expected_balance_centavos' => '2900'])->assertConflict()->assertJsonPath('code', 'balance_changed');
    $this->assertDatabaseCount('sale_reversals', 0);
    $this->getJson($this->base.'/sales/'.$sale['id'].'/reversal-preview')->assertOk()->assertJsonPath('data.cash_refund_centavos', 500)->assertJsonPath('data.credit_reversed_centavos', 2400);
});

it('rolls back restored stock and reversal when the credit audit write fails', function () {
    $id = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Suki'])->assertCreated()->json('data.id');
    $credit = $this->checkoutData($this->product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $id];
    $sale = $this->postJson($this->base.'/sales', $credit)->assertCreated()->json('data');
    CreditLedgerEntry::creating(function ($entry) {
        if ($entry->entry_type === 'reversal') {
            throw new RuntimeException('QA failed credit audit');
        }
    });
    try {
        $this->postJson($this->base.'/sales/'.$sale['id'].'/reversals', [...$this->input, 'expected_balance_centavos' => '2900'])->assertStatus(500);
    } finally {
        CreditLedgerEntry::flushEventListeners();
    }
    $this->assertDatabaseCount('sale_reversals', 0);
    expect($this->product->inventory()->first()->quantity_on_hand)->toBe(6);
    expect($this->store->customers()->findOrFail($id)->balance_centavos)->toBe(2900);
    $this->assertDatabaseMissing('credit_ledger_entries', ['entry_type' => 'reversal']);
});
