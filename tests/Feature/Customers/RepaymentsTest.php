<?php

use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Customers\RecordRepayment;
use Illuminate\Support\Str;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
    $id = $this->postJson($this->base.'/customers', ['name' => 'Aling Linda', 'phone' => '09123456789', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $this->customer = $this->store->customers()->findOrFail($id);
    $this->product = $this->createProduct($this->store);
    $sale = $this->checkoutData($this->product);
    $sale['payment'] = ['method' => 'credit', 'customer_id' => $id];
    $this->saleId = $this->postJson($this->base.'/sales', $sale)->assertCreated()->json('data.id');
    $this->path = $this->base.'/customers/'.$id.'/payments';
    $this->input = ['request_key' => (string) Str::uuid(), 'amount_centavos' => 1000, 'method' => 'cash', 'expected_balance_centavos' => '2900', 'notes' => '  Partial payment  '];
});

it('records partial cash repayment and an auditable ledger without another sale or inventory change', function () {
    $result = $this->postJson($this->path, $this->input)->assertCreated()->assertJsonPath('meta.replayed', false)
        ->assertJsonPath('data.entry_type', 'payment')->assertJsonPath('data.amount_centavos', 1000)
        ->assertJsonPath('data.balance_before_centavos', '2900')->assertJsonPath('data.balance_after_centavos', '1900')
        ->assertJsonPath('data.payment_method', 'cash')->assertJsonPath('data.notes', 'Partial payment')
        ->assertJsonPath('data.recorded_by.name', 'Maria Reyes')->assertJsonPath('data.customer.name', 'Aling Linda')
        ->assertJsonPath('customer.balance_centavos', '1900')->assertJsonPath('data.sale_id', null);
    expect($result->json('data.payment_number'))->toMatch('/^PAY-\d{8,}$/');
    expect($this->customer->fresh()->balance_centavos)->toBe(1900)->and($this->product->inventory->quantity_on_hand)->toBe(8);
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('sale_payments', 0);
    $this->assertDatabaseCount('inventory_movements', 2);
    $this->getJson($this->base.'/sales')->assertOk()->assertJsonPath('meta.summary.net_sales_centavos', '2900')->assertJsonPath('meta.summary.cash_collected_centavos', '0');
    $this->getJson($this->base.'/customers')->assertOk()->assertJsonPath('meta.summary.collections_centavos', '1000')->assertJsonPath('meta.summary.outstanding_centavos', '1900');
    $this->getJson($this->base.'/customers/'.$this->customer->id.'/ledger')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.entry_type', 'payment')->assertJsonPath('data.1.entry_type', 'charge')->assertJsonPath('data.1.recorded_by.name', 'Maria Reyes');
    $this->getJson($this->base.'/sales/'.$this->saleId)->assertOk()->assertJsonPath('data.credit_amount_centavos', 2900)->assertJsonPath('data.credit.balance_after_centavos', '2900');
});

it('settles a balance with full repayment and moves the customer to the settled filter', function () {
    $this->input['amount_centavos'] = 2900;
    $this->postJson($this->path, $this->input)->assertCreated()->assertJsonPath('customer.balance_centavos', '0');
    $this->getJson($this->base.'/customers?balance=settled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.with_balance', 0);
    $this->getJson($this->base.'/customers?balance=outstanding')->assertOk()->assertJsonCount(0, 'data');
    $this->input['request_key'] = (string) Str::uuid();
    $this->input['expected_balance_centavos'] = '0';
    $this->input['amount_centavos'] = 1;
    $this->postJson($this->path, $this->input)->assertUnprocessable();
});

it('replays a payment before current balance checks and preserves saved customer and actor identity', function () {
    $id = $this->postJson($this->path, $this->input)->assertCreated()->json('data.id');
    $this->customer->update(['name' => 'Renamed customer', 'phone' => '09999999999', 'status' => 'archived']);
    $this->owner->update(['name' => 'Renamed owner']);
    $this->input['request_key'] = strtoupper($this->input['request_key']);
    $this->postJson($this->path, $this->input)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $id)->assertJsonPath('data.customer.name', 'Aling Linda')->assertJsonPath('data.recorded_by.name', 'Maria Reyes');
    $this->getJson($this->path.'/by-key/'.$this->input['request_key'])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.balance_after_centavos', '1900');
    expect($this->customer->fresh()->balance_centavos)->toBe(1900);
    $this->assertDatabaseCount('credit_ledger_entries', 2);
});

it('rejects payment keys reused with a different amount notes or reviewed balance', function (string $field) {
    $this->postJson($this->path, $this->input)->assertCreated();
    $this->input[$field] = match ($field) {
        'amount_centavos' => 500, 'notes' => 'Different notes', default => '1900'
    };
    $this->postJson($this->path, $this->input)->assertConflict()->assertJsonPath('code', 'repayment_key_conflict');
    expect($this->customer->fresh()->balance_centavos)->toBe(1900);
})->with(['amount_centavos', 'notes', 'expected_balance_centavos']);

it('requires a fresh review after another repayment changes the balance', function () {
    $this->postJson($this->path, $this->input)->assertCreated();
    $this->input['request_key'] = (string) Str::uuid();
    $this->postJson($this->path, $this->input)->assertConflict()->assertJsonPath('code', 'balance_changed');
    $this->input['expected_balance_centavos'] = '1900';
    $this->postJson($this->path, $this->input)->assertCreated()->assertJsonPath('customer.balance_centavos', '900');
});

it('rolls back the balance when repayment ledger persistence fails and permits a safe retry', function () {
    CreditLedgerEntry::creating(fn () => throw new RuntimeException('Simulated repayment failure'));
    try {
        expect(fn () => app(RecordRepayment::class)->handle($this->store, $this->customer, $this->owner, $this->input))->toThrow(RuntimeException::class);
    } finally {
        CreditLedgerEntry::flushEventListeners();
    }
    expect($this->customer->fresh()->balance_centavos)->toBe(2900);
    $this->assertDatabaseCount('credit_ledger_entries', 1);
    $this->postJson($this->path, $this->input)->assertCreated();
});

it('keeps large customer balances exact and can collect existing debt from archived customers', function () {
    $this->customer->forceFill(['balance_centavos' => 9007199254740993, 'status' => 'archived'])->save();
    $this->input['expected_balance_centavos'] = '9007199254740993';
    $this->postJson($this->path, $this->input)->assertCreated()->assertJsonPath('data.balance_after_centavos', '9007199254739993');
});

it('protects repayment writes history lookups and keys across stores and customers', function () {
    $this->postJson($this->path, $this->input)->assertCreated();
    $otherCustomer = $this->postJson($this->base.'/customers', ['name' => 'Another customer', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $this->getJson($this->base.'/customers/'.$otherCustomer.'/payments/by-key/'.$this->input['request_key'])->assertNotFound();
    $this->postJson($this->base.'/customers/'.$otherCustomer.'/payments', $this->input)->assertConflict()->assertJsonPath('code', 'repayment_key_conflict');
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $other = $this->createOwner();
    $otherStore = $this->ownerStore($other);
    $this->actingAs($other, 'web');
    $this->postJson($this->path, $this->input)->assertNotFound();
    $this->getJson($this->path.'/by-key/'.$this->input['request_key'])->assertNotFound();
    $this->postJson('/api/v1/stores/'.$otherStore->id.'/customers/'.$this->customer->id.'/payments', $this->input)->assertNotFound();
    expect($this->customer->fresh()->balance_centavos)->toBe(1900);
});

it('requires authentication and denies automatic platform admin access', function () {
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->postJson($this->path, $this->input)->assertUnauthorized();
    $this->getJson($this->path.'/by-key/'.$this->input['request_key'])->assertUnauthorized();
    $admin = User::factory()->create();
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->actingAs($admin, 'web')->postJson($this->path, $this->input)->assertNotFound();
});

it('does not expose repayment edits or deletion and uses generic missing-key responses', function () {
    $this->postJson($this->path, $this->input)->assertCreated();
    $this->patchJson($this->path.'/by-key/'.$this->input['request_key'], [])->assertStatus(405);
    $this->deleteJson($this->path.'/by-key/'.$this->input['request_key'])->assertStatus(405);
    $this->getJson($this->path.'/by-key/'.Str::uuid())->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
    $this->getJson($this->path.'/by-key/invalid')->assertNotFound();
});

it('rejects overpayments malformed money unsupported methods and injected ledger fields', function (array $override) {
    $this->postJson($this->path, array_replace($this->input, $override))->assertUnprocessable();
    expect($this->customer->fresh()->balance_centavos)->toBe(2900);
    $this->assertDatabaseCount('credit_ledger_entries', 1);
})->with([
    [['amount_centavos' => 0]], [['amount_centavos' => -1]], [['amount_centavos' => 1.5]], [['amount_centavos' => 2901]], [['amount_centavos' => 1000000001]],
    [['expected_balance_centavos' => 2900]], [['expected_balance_centavos' => '-1']], [['expected_balance_centavos' => '02900']],
    [['request_key' => 'bad']], [['method' => 'gcash']], [['method' => 'credit']], [['notes' => str_repeat('x', 256)]],
    [['store_id' => 1]], [['customer_id' => 1]], [['actor_user_id' => 1]], [['sale_id' => 1]], [['balance_after_centavos' => 0]], [['entry_type' => 'charge']],
]);
