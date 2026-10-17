<?php

use App\Models\Customer;
use App\Models\User;
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
    $this->path = $this->base.'/customers/'.$id;
    $this->opening = ['request_key' => (string) Str::uuid(), 'amount_centavos' => 5000, 'notes' => '  Copied from notebook  '];
});

it('edits contact details without changing balance or saved payment identity', function () {
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated();
    $payment = $this->postJson($this->path.'/payments', ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 1000, 'expected_balance_centavos' => '5000'])->assertCreated()->json('data');
    $this->patchJson($this->path, ['name' => '  Linda Reyes  ', 'phone' => ' 09999999999 ', 'notes' => ' Updated details '])->assertOk()->assertJsonPath('data.name', 'Linda Reyes')->assertJsonPath('data.phone', '09999999999')->assertJsonPath('data.balance_centavos', '4000')->assertJsonPath('data.can_record_opening_balance', false);
    $this->getJson($this->path.'/payments/'.$payment['id'])->assertOk()->assertJsonPath('data.customer.name', 'Aling Linda')->assertJsonPath('data.customer.phone', '09123456789')->assertJsonPath('data.balance_after_centavos', '4000');
    $this->getJson($this->path.'/opening-balance/by-key/'.$this->opening['request_key'])->assertOk()->assertJsonPath('data.customer.name', 'Aling Linda');
});

it('archives indebted customers without hiding debt and permits collection and restoration', function () {
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated();
    $this->patchJson($this->path.'/status', ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
    $this->getJson($this->base.'/customers')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.summary.outstanding_centavos', '5000');
    $this->getJson($this->base.'/customers?status=archived')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->base.'/customers?status=all')->assertOk()->assertJsonCount(1, 'data');
    $product = $this->createProduct($this->store);
    $sale = $this->checkoutData($product);
    $sale['payment'] = ['method' => 'credit', 'customer_id' => $this->customer->id];
    $this->postJson($this->base.'/sales', $sale)->assertConflict();
    $this->postJson($this->path.'/payments', ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 1000, 'expected_balance_centavos' => '5000'])->assertCreated()->assertJsonPath('customer.balance_centavos', '4000');
    $this->patchJson($this->path.'/status', ['status' => 'active'])->assertOk();
    $this->postJson($this->base.'/sales', $sale)->assertCreated();
    $this->getJson($this->path.'/ledger')->assertOk()->assertJsonCount(3, 'data');
});

it('records opening utang once without creating sales collections payments or stock changes', function () {
    $this->getJson($this->path)->assertOk()->assertJsonPath('data.can_record_opening_balance', true);
    $result = $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated()->assertJsonPath('data.entry_type', 'opening_balance')->assertJsonPath('data.notes', 'Copied from notebook')->assertJsonPath('data.balance_before_centavos', '0')->assertJsonPath('data.balance_after_centavos', '5000')->assertJsonPath('data.payment_method', null)->assertJsonPath('customer.can_record_opening_balance', false);
    expect($result->json('data.opening_number'))->toMatch('/^OPEN-\d{8,}$/');
    $this->opening['request_key'] = strtoupper($this->opening['request_key']);
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $result->json('data.id'));
    $this->assertDatabaseCount('credit_ledger_entries', 1);
    foreach (['sales', 'sale_items', 'sale_payments', 'inventory_movements'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $day = now('Asia/Manila')->toDateString();
    $this->getJson($this->base.'/reports/sales?date_from='.$day.'&date_to='.$day)->assertOk()->assertJsonPath('data.summary.net_sales_centavos', '0')->assertJsonPath('data.summary.cash_collected_centavos', '0');
});

it('rejects opening after any financial history even after settlement and preserves original snapshots on replay', function () {
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated();
    $this->postJson($this->path.'/payments', ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 5000, 'expected_balance_centavos' => '5000'])->assertCreated();
    $this->postJson($this->path.'/opening-balance', [...$this->opening, 'request_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'opening_not_available');
    $this->customer->update(['name' => 'Renamed', 'status' => 'archived']);
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertOk()->assertJsonPath('data.customer.name', 'Aling Linda')->assertJsonPath('data.balance_after_centavos', '5000')->assertJsonPath('customer.balance_centavos', '0');
});

it('rejects opening on archived customers and on customers whose first transaction was a sale', function () {
    $this->customer->update(['status' => 'archived']);
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertConflict();
    $this->customer->update(['status' => 'active']);
    $product = $this->createProduct($this->store);
    $sale = $this->checkoutData($product);
    $sale['payment'] = ['method' => 'credit', 'customer_id' => $this->customer->id];
    $this->postJson($this->base.'/sales', $sale)->assertCreated();
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertConflict();
});

it('rejects reused opening keys with changed details or another customer', function () {
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated();
    $this->postJson($this->path.'/opening-balance', [...$this->opening, 'amount_centavos' => 6000])->assertConflict()->assertJsonPath('code', 'opening_key_conflict');
    $id = $this->postJson($this->base.'/customers', ['name' => 'Other', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $this->postJson($this->base.'/customers/'.$id.'/opening-balance', $this->opening)->assertConflict();
    $this->getJson($this->base.'/customers/'.$id.'/opening-balance/by-key/'.$this->opening['request_key'])->assertNotFound();
});

it('rolls back the opening audit if saving the resulting balance fails', function () {
    Customer::saving(function (Customer $customer) {
        if ($customer->balance_centavos > 0) {
            throw new RuntimeException('Simulated write failure');
        }
    });
    try {
        $this->postJson($this->path.'/opening-balance', $this->opening)->assertStatus(500);
    } finally {
        Customer::flushEventListeners();
    }
    expect($this->customer->fresh()->balance_centavos)->toBe(0);
    $this->assertDatabaseCount('credit_ledger_entries', 0);
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated();
});

it('paginates a stable statement while new payments arrive and includes opening debt separately', function () {
    $first = $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated()->json('data.id');
    $pay = fn (string $balance) => $this->postJson($this->path.'/payments', ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 1000, 'expected_balance_centavos' => $balance])->assertCreated();
    $last = $pay('5000')->json('data.id');
    $this->getJson($this->path.'/statement?per_page=1')->assertOk()->assertJsonPath('snapshot_id', $last)->assertJsonPath('data.0.id', $first)->assertJsonPath('customer.balance_centavos', '4000')->assertJsonPath('totals.opening_centavos', '5000')->assertJsonPath('totals.payments_centavos', '1000')->assertJsonPath('totals.charges_centavos', '0');
    $pay('4000');
    $this->getJson($this->path.'/statement?per_page=1&page=2&through_id='.$last)->assertOk()->assertJsonPath('data.0.id', $last)->assertJsonPath('meta.total', 2)->assertJsonPath('customer.balance_centavos', '4000')->assertJsonPath('totals.payments_centavos', '1000');
    $this->getJson($this->path.'/statement')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('customer.balance_centavos', '3000');
});

it('supports an empty pinned statement and refuses foreign payment or statement entry ids', function () {
    $this->getJson($this->path.'/statement')->assertOk()->assertJsonPath('snapshot_id', 0)->assertJsonCount(0, 'data');
    $entry = $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated()->json('data.id');
    $this->getJson($this->path.'/statement?through_id=0')->assertOk()->assertJsonPath('customer.balance_centavos', '0')->assertJsonCount(0, 'data');
    $id = $this->postJson($this->base.'/customers', ['name' => 'Other', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $this->getJson($this->base.'/customers/'.$id.'/statement?through_id='.$entry)->assertNotFound();
    $this->getJson($this->path.'/payments/'.$entry)->assertNotFound();
});

it('reconciles statement opening charges payments and pooled credit reversals while preserving sale identity', function () {
    $this->postJson($this->path.'/opening-balance', $this->opening)->assertCreated();
    $product = $this->createProduct($this->store);
    $input = $this->checkoutData($product);
    $input['payment'] = ['method' => 'credit', 'customer_id' => $this->customer->id];
    $sale = $this->postJson($this->base.'/sales', $input)->assertCreated()->json('data');
    $this->postJson($this->path.'/payments', ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 1000, 'expected_balance_centavos' => '7900'])->assertCreated();
    $this->patchJson($this->path, ['name' => 'Updated contact'])->assertOk();
    $this->postJson($this->base.'/sales/'.$sale['id'].'/reversals', ['request_key' => (string) Str::uuid(), 'kind' => 'refund', 'reason' => 'Returned the complete order', 'restock' => false, 'expected_balance_centavos' => '6900', 'cash_confirmed' => false])->assertCreated();
    $this->getJson($this->path.'/statement')->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('customer.name', 'Updated contact')->assertJsonPath('customer.balance_centavos', '4000')
        ->assertJsonPath('totals.opening_centavos', '5000')->assertJsonPath('totals.charges_centavos', '2900')
        ->assertJsonPath('totals.payments_centavos', '1000')->assertJsonPath('totals.reversals_centavos', '2900')
        ->assertJsonPath('data.3.reversal.sale_number', $sale['sale_number']);
    $this->getJson($this->base.'/sales/'.$sale['id'])->assertOk()->assertJsonPath('data.customer.name', 'Aling Linda');
});

it('protects customer edits status opening statements and slips across tenants and from platform admins', function () {
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $other = $this->createOwner();
    foreach ([$other, User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active'])] as $user) {
        $this->resetBrowserGuards();
        $this->actingAs($user, 'web');
        $this->getJson($this->path)->assertNotFound();
        $this->patchJson($this->path, ['name' => 'Hacked'])->assertNotFound();
        $this->patchJson($this->path.'/status', ['status' => 'archived'])->assertNotFound();
        $this->postJson($this->path.'/opening-balance', $this->opening)->assertNotFound();
        $this->getJson($this->path.'/opening-balance/by-key/'.$this->opening['request_key'])->assertNotFound();
        $this->getJson($this->path.'/statement')->assertNotFound();
        $this->getJson($this->path.'/payments/1')->assertNotFound();
    }
});

it('rejects malformed opening amounts notes keys and injected balances', function (array $overrides) {
    $this->postJson($this->path.'/opening-balance', [...$this->opening, ...$overrides])->assertUnprocessable();
    expect($this->customer->fresh()->balance_centavos)->toBe(0);
    $this->assertDatabaseCount('credit_ledger_entries', 0);
})->with([[['amount_centavos' => 0]], [['amount_centavos' => -1]], [['amount_centavos' => 1.1]], [['amount_centavos' => 1000000001]], [['notes' => '  ']], [['notes' => str_repeat('x', 256)]], [['request_key' => 'bad']], [['balance_centavos' => 100]], [['store_id' => 1]], [['payment_method' => 'cash']]]);

it('rejects balance privilege and status injection in customer maintenance', function () {
    $this->patchJson($this->path, ['name' => 'Linda', 'balance_centavos' => 100])->assertUnprocessable();
    $this->patchJson($this->path, ['name' => ['bad']])->assertUnprocessable();
    $this->patchJson($this->path.'/status', ['status' => 'deleted'])->assertUnprocessable();
    $this->patchJson($this->path.'/status', ['status' => 'active', 'balance_centavos' => 1])->assertUnprocessable();
    $this->getJson($this->base.'/customers?status=bad')->assertUnprocessable();
    $this->getJson($this->path.'/statement?through_id=-1')->assertUnprocessable();
});
