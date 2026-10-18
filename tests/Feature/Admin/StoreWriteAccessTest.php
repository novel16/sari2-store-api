<?php

use App\Models\Account;
use App\Models\Store;
use App\Services\Auth\RegisterStoreOwner;
use App\Services\Cash\CashDayService;
use App\Services\Catalog\CategoryWriter;
use App\Services\Catalog\ProductWriter;
use App\Services\Catalog\StockAdjustment;
use App\Services\Customers\CreateCustomer;
use App\Services\Customers\RecordOpeningBalance;
use App\Services\Customers\RecordRepayment;
use App\Services\Sales\CompleteSale;
use App\Services\Sales\ReverseSale;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->store = $this->ownerStore($this->owner)->load('account');
    $this->product = app(ProductWriter::class)->save($this->store, $this->owner, [
        'name' => 'Guarded product', 'unit' => 'piece', 'price_centavos' => 1000,
        'cost_centavos' => 500, 'initial_stock' => 10,
    ]);
    $this->customer = app(CreateCustomer::class)->handle($this->store, [
        'name' => 'Guarded customer', 'request_key' => (string) Str::uuid(),
    ])['customer'];
    app(RecordOpeningBalance::class)->handle($this->store, $this->customer, $this->owner, [
        'request_key' => (string) Str::uuid(), 'amount_centavos' => 5000, 'notes' => 'Legacy customer balance',
    ]);
    $this->saleInput = ['idempotency_key' => (string) Str::uuid(),
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_centavos' => 1000]];
    $this->sale = app(CompleteSale::class)->handle($this->store, $this->owner, $this->saleInput)['sale'];
    $this->cashInput = ['request_key' => (string) Str::uuid(), 'opening_cash_centavos' => 5000,
        'business_date' => now('Asia/Manila')->toDateString()];
    $this->day = app(CashDayService::class)->open($this->store, $this->owner, $this->cashInput)['day'];
    $this->review = app(CashDayService::class)->preview($this->store, $this->day, $this->owner, [
        'counted_cash_centavos' => 6000,
    ]);
});

it('rejects writes from an already admitted request after current availability changes', function (string $target, string $operation) {
    // The route-bound store and its eager-loaded account remain active in memory.
    // Each service must acquire current account/store locks instead of trusting them.
    $model = $target === 'account' ? Account::class : Store::class;
    $id = $target === 'account' ? $this->store->account_id : $this->store->id;
    $model::whereKey($id)->update(['status' => 'suspended']);
    expect($this->store->status)->toBe('active');
    expect($this->store->account->status)->toBe('active');
    $before = [];
    foreach (['products', 'categories', 'customers', 'sales', 'sale_items', 'sale_payments',
        'sale_reversals', 'credit_ledger_entries', 'inventory_movements', 'cash_days', 'cash_movements'] as $table) {
        $before[$table] = DB::table($table)->count();
    }
    $action = fn () => match ($operation) {
        'checkout' => app(CompleteSale::class)->handle($this->store, $this->owner, [
            ...$this->saleInput, 'idempotency_key' => (string) Str::uuid(),
        ]),
        'checkout replay' => app(CompleteSale::class)->handle($this->store, $this->owner, $this->saleInput),
        'refund' => app(ReverseSale::class)->handle($this->store, $this->sale, $this->owner, [
            'request_key' => (string) Str::uuid(), 'kind' => 'refund', 'reason' => 'Returned item',
            'restock' => true, 'expected_balance_centavos' => null, 'cash_confirmed' => true,
        ]),
        'repayment' => app(RecordRepayment::class)->handle($this->store, $this->customer, $this->owner, [
            'request_key' => (string) Str::uuid(), 'amount_centavos' => 1000,
            'method' => 'cash', 'expected_balance_centavos' => '5000',
        ]),
        'opening debt' => app(RecordOpeningBalance::class)->handle($this->store, $this->customer, $this->owner, [
            'request_key' => (string) Str::uuid(), 'amount_centavos' => 1000, 'notes' => 'Opening debt',
        ]),
        'customer' => app(CreateCustomer::class)->handle($this->store, [
            'name' => 'New customer', 'request_key' => (string) Str::uuid(),
        ]),
        'category' => app(CategoryWriter::class)->save($this->store, ['name' => 'New category']),
        'product' => app(ProductWriter::class)->save($this->store, $this->owner, [
            'name' => 'Changed product', 'unit' => 'piece', 'price_centavos' => 1000,
            'cost_centavos' => 500,
        ], $this->product),
        'stock' => app(StockAdjustment::class)->handle($this->store, $this->owner, [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->id,
            'mode' => 'add', 'quantity' => 2, 'reason' => 'New shipment',
        ]),
        'cash opening replay' => app(CashDayService::class)->open($this->store, $this->owner, $this->cashInput),
        'cash movement' => app(CashDayService::class)->move($this->store, $this->day, $this->owner, [
            'request_key' => (string) Str::uuid(), 'direction' => 'in', 'amount_centavos' => 1000,
            'reason' => 'Extra float',
        ]),
        'cash close' => app(CashDayService::class)->close($this->store, $this->day, $this->owner, [
            'request_key' => (string) Str::uuid(), 'review_token' => $this->review['review_token'],
        ]),
    };
    try {
        $action();
        $this->fail('An unavailable store must not commit or replay a mutation.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    foreach ($before as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    expect($this->product->inventory()->sole()->quantity_on_hand)->toBe(9);
    expect($this->product->fresh()->name)->toBe('Guarded product');
    expect($this->customer->fresh()->balance_centavos)->toBe(5000);
    expect($this->day->fresh()->status)->toBe('open');
})->with(['account', 'store'])->with([
    'checkout', 'checkout replay', 'refund', 'repayment', 'opening debt', 'customer',
    'category', 'product', 'stock', 'cash opening replay', 'cash movement', 'cash close',
]);

it('requires its access lock to remain inside the mutation transaction', function () {
    // RefreshDatabase wraps the fixture in a transaction; explicitly finish it.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    expect(fn () => StoreWriteAccess::lock($this->store))->toThrow(LogicException::class);
});
