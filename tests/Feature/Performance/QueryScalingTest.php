<?php

use App\Http\Resources\CustomerResource;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CountsDatabaseQueries;
use Tests\Support\SalesTestHelpers;
use Tests\TestCase;

uses(SalesTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
});

function scalingProducts(TestCase $test, int $count = 12): array
{
    $products = [];
    for ($index = 1; $index <= $count; $index++) {
        $category = $test->store->categories()->create(['name' => 'Category '.$index, 'name_key' => 'category-'.$index, 'status' => 'active']);
        $id = $test->postJson($test->base.'/products', ['name' => 'Product '.$index, 'unit' => 'piece',
            'category_id' => $category->id, 'price_centavos' => 100, 'cost_centavos' => 50,
            'low_stock_threshold' => 5, 'initial_stock' => 100])->assertCreated()->json('data.id');
        $products[] = Product::findOrFail($id);
    }

    return $products;
}

function scalingCheckout(array $products, string $method = 'cash', ?int $customerId = null): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'items' => array_map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_centavos' => 100], array_reverse($products)),
        'discount_centavos' => 0,
        'payment' => $method === 'cash' ? ['method' => 'cash', 'tendered_centavos' => 100000] : ['method' => 'credit', 'customer_id' => $customerId],
    ];
}

function scalingReversal(): array
{
    return ['request_key' => (string) Str::uuid(), 'kind' => 'refund', 'reason' => 'Returned unopened',
        'restock' => true, 'expected_balance_centavos' => null, 'cash_confirmed' => true];
}

it('keeps catalog list reads constant with different categories and larger pages', function (string $endpoint) {
    scalingProducts($this);
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($this->base.'/'.$endpoint.'?per_page=1')->assertOk()->assertJsonCount(1, 'data'),
        fn () => $this->getJson($this->base.'/'.$endpoint.'?per_page=12')->assertOk()->assertJsonCount(12, 'data'),
    );
})->with(['products', 'categories', 'inventory']);

it('batches inventory movement products and actors across the whole page', function () {
    foreach (scalingProducts($this) as $product) {
        $product->movements()->update(['actor_user_id' => User::factory()->create()->id]);
    }
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($this->base.'/inventory/movements?per_page=1')->assertOk()->assertJsonCount(1, 'data'),
        fn () => $this->getJson($this->base.'/inventory/movements?per_page=12')->assertOk()->assertJsonCount(12, 'data'),
    );
});

it('reads customer history existence in the list query instead of querying each customer', function () {
    for ($index = 0; $index < 12; $index++) {
        $customer = $this->store->customers()->create(['name' => 'Customer '.$index, 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
        if ($index % 2) {
            $customer->ledgerEntries()->create(['store_id' => $this->store->id, 'actor_user_id' => $this->owner->id,
                'entry_type' => 'opening_balance', 'amount_centavos' => 100, 'balance_before_centavos' => 0, 'balance_after_centavos' => 100]);
            $customer->ledgerEntries()->create(['store_id' => $this->store->id, 'actor_user_id' => $this->owner->id,
                'entry_type' => 'payment', 'amount_centavos' => 100, 'balance_before_centavos' => 100, 'balance_after_centavos' => 0]);
        }
    }
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($this->base.'/customers?per_page=1')->assertOk()->assertJsonCount(1, 'data'),
        fn () => $this->getJson($this->base.'/customers?per_page=12')->assertOk()->assertJsonCount(12, 'data'),
    );
    $customers = Customer::withExists('ledgerEntries')->get();
    expect($this->readQueries(function () use ($customers) {
        $data = CustomerResource::collection($customers)->resolve();
        expect(array_column($data, 'can_record_opening_balance'))->toContain(true, false);
    }))->toBe([]);

    // A resource without the projection must not perform a hidden existence query.
    $unprojected = Customer::firstOrFail();
    expect($this->readQueries(fn () => (new CustomerResource($unprojected))->resolve()))->toBe([]);
});

it('batches ledger sales, historical actors and nested reversal sales', function (string $endpoint) {
    $product = scalingProducts($this, 1)[0];
    $customerId = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Ledger customer'])->assertCreated()->json('data.id');
    for ($index = 0; $index < 4; $index++) {
        $saleId = $this->postJson($this->base.'/sales', scalingCheckout([$product], 'credit', $customerId))->assertCreated()->json('data.id');
        $paymentId = $this->postJson($this->base.'/customers/'.$customerId.'/payments', [
            'request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 40, 'expected_balance_centavos' => '100',
        ])->assertCreated()->json('data.id');
        // Legacy receipts fall back to the actor relation when a snapshot is absent.
        DB::table('credit_ledger_entries')->where('id', $paymentId)->update(['actor_name_snapshot' => null]);
        $this->postJson($this->base.'/sales/'.$saleId.'/reversals', [...scalingReversal(), 'restock' => false, 'expected_balance_centavos' => '60'])->assertCreated();
    }
    $path = $this->base.'/customers/'.$customerId.'/'.$endpoint;
    // Both page sizes include charge/payment/reversal entries and all related record types.
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($path.'?per_page=3')->assertOk()->assertJsonCount(3, 'data'),
        fn () => $this->getJson($path.'?per_page=12')->assertOk()->assertJsonCount(12, 'data'),
    );
})->with(['ledger', 'statement']);

it('batches sale history payments and reversal metadata', function () {
    $product = scalingProducts($this, 1)[0];
    for ($index = 0; $index < 12; $index++) {
        $saleId = $this->postJson($this->base.'/sales', scalingCheckout([$product]))->assertCreated()->json('data.id');
    }
    $this->postJson($this->base.'/sales/'.$saleId.'/reversals', scalingReversal())->assertCreated();
    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($this->base.'/sales?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'refunded'),
        fn () => $this->getJson($this->base.'/sales?per_page=12')->assertOk()->assertJsonCount(12, 'data'),
    );
});

it('keeps checkout reads constant as the cart grows while updating every stock row', function (string $method) {
    $products = scalingProducts($this);
    $customerId = $method === 'credit' ? $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Cart customer'])->assertCreated()->json('data.id') : null;
    $this->assertReadQueriesStayConstant(
        fn () => $this->postJson($this->base.'/sales', scalingCheckout([$products[0]], $method, $customerId))->assertCreated()->assertJsonPath('data.total_centavos', 100),
        fn () => $this->postJson($this->base.'/sales', scalingCheckout($products, $method, $customerId))->assertCreated()->assertJsonPath('data.total_centavos', 1200)->assertJsonCount(12, 'data.items'),
    );
    foreach ($products as $index => $product) {
        $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity_on_hand' => $index === 0 ? 98 : 99]);
    }
    if ($customerId) {
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'balance_centavos' => 1300]);
    }
})->with(['cash', 'credit']);

it('keeps restocking reads constant as a refunded sale grows', function () {
    $products = scalingProducts($this);
    $smallSale = $this->postJson($this->base.'/sales', scalingCheckout([$products[0]]))->assertCreated()->json('data.id');
    $largeSale = $this->postJson($this->base.'/sales', scalingCheckout($products))->assertCreated()->json('data.id');
    $this->assertReadQueriesStayConstant(
        fn () => $this->postJson($this->base.'/sales/'.$smallSale.'/reversals', scalingReversal())->assertCreated(),
        fn () => $this->postJson($this->base.'/sales/'.$largeSale.'/reversals', scalingReversal())->assertCreated()->assertJsonPath('data.status', 'refunded'),
    );
    foreach ($products as $product) {
        $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity_on_hand' => 100]);
    }
    $this->assertDatabaseCount('inventory_movements', 38); // 12 opening + 13 sold + 13 returned.
});

it('loads owner workspaces stores subscriptions and plans in batches', function () {
    $initial = $this->readQueries(fn () => $this->getJson('/api/v1/me')->assertOk()->assertJsonCount(1, 'data.accounts'));
    for ($index = 1; $index < 12; $index++) {
        $account = Account::create(['name' => 'Workspace '.$index, 'owner_user_id' => $this->owner->id, 'status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $this->owner->id, 'role' => 'owner', 'status' => 'active']);
        Store::create(['name' => $account->name, 'slug' => 'store', 'account_id' => $account->id, 'status' => 'active']);
        Subscription::create(['account_id' => $account->id, 'plan_id' => Plan::where('code', 'free')->value('id'), 'status' => 'active', 'starts_at' => now()]);
    }
    $expanded = $this->readQueries(fn () => $this->getJson('/api/v1/me')->assertOk()->assertJsonCount(12, 'data.accounts'));
    expect(count($expanded))->toBe(count($initial));
});

it('fails fast on accidental relationship lazy loading in development and tests', function () {
    scalingProducts($this, 2);
    expect(Model::preventsLazyLoading())->toBeTrue();
    $products = Product::all();
    expect(fn () => $products->first()->category)->toThrow(LazyLoadingViolationException::class);
});
