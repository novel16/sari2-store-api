<?php

use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);

function dashboardSale(Store $store, User $owner, array $overrides = []): Sale
{
    return $store->sales()->create(array_replace([
        'sold_by_user_id' => $owner->id, 'sold_by_name_snapshot' => $owner->name,
        'store_name_snapshot' => $store->name, 'sale_number' => 'D-'.Str::uuid(),
        'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        'status' => 'completed', 'payment_method' => 'cash', 'subtotal_centavos' => 100,
        'discount_centavos' => 0, 'total_centavos' => 100, 'amount_paid_centavos' => 100,
        'items_count' => 1, 'quantity_total' => 1, 'completed_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:30:00', 'UTC'));
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
});

afterEach(fn () => Carbon::setTestNow());

it('returns honest zero states and zero-filled Manila days for an empty store', function () {
    $response = $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.business_date', '2026-10-16')
        ->assertJsonPath('data.today.transactions', 0)
        ->assertJsonPath('data.today.net_sales_centavos', '0')
        ->assertJsonPath('data.today.cash_collected_centavos', '0')
        ->assertJsonPath('data.credit.outstanding_centavos', '0')
        ->assertJsonPath('data.stock.active_products', 0)
        ->assertJsonPath('data.stock.alerts', [])
        ->assertJsonPath('data.recent_sales', [])
        ->assertJsonPath('data.top_products', [])
        ->assertJsonPath('data.chart.period', 'this_week')
        ->assertJsonPath('data.chart.date_from', '2026-10-12')
        ->assertJsonPath('data.chart.date_to', '2026-10-16')
        ->assertJsonCount(5, 'data.chart.days');
    foreach ($response->json('data.chart.days') as $day) {
        expect($day['net_sales_centavos'])->toBe('0');
        expect($day['transactions'])->toBe(0);
    }
});

it('reconciles actual discounted cash and utang sales with repayments without counting change or retries', function () {
    $product = $this->createProduct($this->store);
    $customer = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Linda'])->assertCreated()->json('data.id');
    $cash = $this->checkoutData($product);
    $this->postJson($this->base.'/sales', $cash)->assertCreated();
    $this->postJson($this->base.'/sales', $cash)->assertOk();
    $credit = $this->checkoutData($product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $customer];
    $this->postJson($this->base.'/sales', $credit)->assertCreated();
    $payment = ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 1000, 'expected_balance_centavos' => '2900'];
    $this->postJson($this->base.'/customers/'.$customer.'/payments', $payment)->assertCreated();
    $this->postJson($this->base.'/customers/'.$customer.'/payments', $payment)->assertOk();

    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.today.transactions', 2)
        ->assertJsonPath('data.today.gross_sales_centavos', '6000')
        ->assertJsonPath('data.today.discounts_centavos', '200')
        ->assertJsonPath('data.today.net_sales_centavos', '5800')
        ->assertJsonPath('data.today.cash_sales_centavos', '2900')
        ->assertJsonPath('data.today.credit_sales_centavos', '2900')
        ->assertJsonPath('data.today.repayments_centavos', '1000')
        ->assertJsonPath('data.today.cash_collected_centavos', '3900')
        ->assertJsonPath('data.credit.outstanding_centavos', '1900')
        ->assertJsonPath('data.credit.customers_with_balance', 1)
        ->assertJsonPath('data.chart.days.4.net_sales_centavos', '5800')
        ->assertJsonPath('data.chart.days.4.transactions', 2)
        ->assertJsonPath('data.top_products.0.units_sold', '4')
        ->assertJsonCount(2, 'data.recent_sales');
});

it('counts todays collection of an old debt without adding a sale today', function () {
    $product = $this->createProduct($this->store);
    $customer = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Old debt'])->assertCreated()->json('data.id');
    $credit = $this->checkoutData($product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $customer];
    Carbon::setTestNow(Carbon::parse('2026-10-15 15:59:59', 'UTC'));
    $this->postJson($this->base.'/sales', $credit)->assertCreated();
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:30:00', 'UTC'));
    $this->postJson($this->base.'/customers/'.$customer.'/payments', [
        'request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 2900, 'expected_balance_centavos' => '2900',
    ])->assertCreated();
    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.today.transactions', 0)
        ->assertJsonPath('data.today.net_sales_centavos', '0')
        ->assertJsonPath('data.today.repayments_centavos', '2900')
        ->assertJsonPath('data.today.cash_collected_centavos', '2900')
        ->assertJsonPath('data.credit.outstanding_centavos', '0');
});

it('uses inclusive Manila midnight and exclusive next midnight for sales and daily buckets', function () {
    foreach (['2026-10-15 15:59:59', '2026-10-15 16:00:00', '2026-10-16 15:59:59', '2026-10-16 16:00:00'] as $timestamp) {
        dashboardSale($this->store, $this->owner, ['completed_at' => $timestamp]);
    }
    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.today.transactions', 2)
        ->assertJsonPath('data.today.net_sales_centavos', '200')
        ->assertJsonPath('data.chart.days.3.net_sales_centavos', '100')
        ->assertJsonPath('data.chart.days.4.net_sales_centavos', '200');
});

it('uses the same midnight bounds for cash repayments and ignores non-payment ledger entries', function () {
    $id = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Boundary'])->assertCreated()->json('data.id');
    $customer = $this->store->customers()->findOrFail($id);
    foreach (['2026-10-15 15:59:59', '2026-10-15 16:00:00', '2026-10-16 15:59:59', '2026-10-16 16:00:00'] as $timestamp) {
        $entry = $customer->ledgerEntries()->make([
            'store_id' => $this->store->id, 'actor_user_id' => $this->owner->id, 'entry_type' => 'payment',
            'payment_method' => 'cash', 'amount_centavos' => 100, 'balance_before_centavos' => 100,
            'balance_after_centavos' => 0,
        ]);
        $entry->forceFill(['created_at' => $timestamp])->save();
    }
    $customer->ledgerEntries()->create([
        'store_id' => $this->store->id, 'actor_user_id' => $this->owner->id, 'entry_type' => 'charge',
        'amount_centavos' => 999, 'balance_before_centavos' => 0, 'balance_after_centavos' => 999,
    ]);
    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.today.repayments_centavos', '200')
        ->assertJsonPath('data.today.cash_collected_centavos', '200')
        ->assertJsonPath('data.today.transactions', 0);
});

it('keeps today KPIs independent of the selected chart period', function () {
    dashboardSale($this->store, $this->owner, ['completed_at' => '2026-10-05 04:00:00', 'total_centavos' => 700]);
    dashboardSale($this->store, $this->owner);
    $this->getJson($this->base.'/dashboard?period=last_week')->assertOk()
        ->assertJsonPath('data.chart.date_from', '2026-10-05')
        ->assertJsonPath('data.chart.date_to', '2026-10-11')
        ->assertJsonCount(7, 'data.chart.days')
        ->assertJsonPath('data.chart.days.0.net_sales_centavos', '700')
        ->assertJsonPath('data.today.transactions', 1)
        ->assertJsonPath('data.today.net_sales_centavos', '100');
});

it('builds calendar periods across week month year and leap-day boundaries', function ($now, $period, $from, $to, $count) {
    Carbon::setTestNow(Carbon::parse($now, 'UTC'));
    $this->getJson($this->base.'/dashboard?period='.$period)->assertOk()
        ->assertJsonPath('data.chart.date_from', $from)->assertJsonPath('data.chart.date_to', $to)
        ->assertJsonCount($count, 'data.chart.days');
})->with([
    ['2026-10-18 16:00:00', 'this_week', '2026-10-19', '2026-10-19', 1],
    ['2026-10-18 15:59:59', 'this_week', '2026-10-12', '2026-10-18', 7],
    ['2026-12-31 16:00:00', 'this_month', '2027-01-01', '2027-01-01', 1],
    ['2026-12-31 16:00:00', 'last_week', '2026-12-21', '2026-12-27', 7],
    ['2028-02-29 04:00:00', 'this_month', '2028-02-01', '2028-02-29', 29],
    ['2026-10-31 04:00:00', 'this_month', '2026-10-01', '2026-10-31', 31],
]);

it('shows exact large balances and retains unpaid archived customers', function () {
    foreach ([['Active', 'active', 9007199254740993], ['Archived', 'archived', 100], ['Settled', 'active', 0]] as [$name, $status, $balance]) {
        $customer = $this->store->customers()->create(['name' => $name, 'status' => $status, 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64)]);
        $customer->forceFill(['balance_centavos' => $balance])->save();
    }
    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.credit.outstanding_centavos', '9007199254741093')
        ->assertJsonPath('data.credit.customers_with_balance', 2)
        ->assertJsonPath('data.credit.archived_with_balance', 1);
});

it('counts active stock at the exact threshold and limits alerts to the five most urgent products', function () {
    $this->createProduct($this->store, ['name' => 'Healthy', 'initial_stock' => 6]);
    $this->createProduct($this->store, ['name' => 'At threshold', 'initial_stock' => 5]);
    $this->createProduct($this->store, ['name' => 'Below threshold', 'initial_stock' => 4]);
    for ($i = 0; $i < 5; $i++) {
        $this->createProduct($this->store, ['name' => 'Empty '.$i, 'initial_stock' => 0]);
    }
    $archived = $this->createProduct($this->store, ['name' => 'Archived', 'initial_stock' => 0]);
    $archived->update(['status' => 'archived']);
    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.stock.active_products', 8)
        ->assertJsonPath('data.stock.in_stock', 1)
        ->assertJsonPath('data.stock.low_stock', 2)
        ->assertJsonPath('data.stock.out_of_stock', 5)
        ->assertJsonCount(5, 'data.stock.alerts')
        ->assertJsonPath('data.stock.alerts.0.name', 'Empty 0')
        ->assertJsonPath('data.stock.alerts.4.name', 'Empty 4');
});

it('ranks quantities across completed period sales and preserves saved names after catalog changes', function () {
    $product = $this->createProduct($this->store);
    $sale = dashboardSale($this->store, $this->owner);
    $sale->items()->create([
        'store_id' => $this->store->id, 'product_id' => $product->id,
        'product_name_snapshot' => 'Saved water', 'unit_snapshot' => 'bottle', 'quantity' => 3,
        'unit_price_centavos' => 1500, 'unit_cost_centavos' => 1000, 'line_total_centavos' => 4500,
    ]);
    $product->update(['name' => 'New name', 'unit' => 'pack', 'status' => 'archived']);
    $sale->update(['sold_by_name_snapshot' => 'Saved cashier']);
    $this->owner->update(['name' => 'Renamed owner']);
    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.top_products.0.name', 'Saved water')
        ->assertJsonPath('data.top_products.0.unit', 'bottle')
        ->assertJsonPath('data.top_products.0.units_sold', '3')
        ->assertJsonPath('data.recent_sales.0.sold_by.name', 'Saved cashier');
    $this->getJson($this->base.'/dashboard?period=last_week')->assertOk()->assertJsonPath('data.top_products', []);
});

it('returns only the latest five completed sales in stable timestamp and id order', function () {
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        $ids[] = dashboardSale($this->store, $this->owner)->id;
    }
    dashboardSale($this->store, $this->owner, ['status' => 'voided', 'total_centavos' => 9999]);
    $response = $this->getJson($this->base.'/dashboard')->assertOk()->assertJsonCount(5, 'data.recent_sales')
        ->assertJsonPath('data.today.transactions', 7)
        ->assertJsonPath('data.today.net_sales_centavos', '700')
        ->assertJsonPath('data.chart.days.4.transactions', 7);
    expect(array_column($response->json('data.recent_sales'), 'id'))->toBe(array_slice(array_reverse($ids), 0, 5));
});

it('aggregates top products across sales limits to five and excludes voided and outside-period lines', function () {
    $products = [];
    $addLine = function ($sale, $product, $quantity) {
        $sale->items()->create([
            'store_id' => $this->store->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'unit_snapshot' => 'piece',
            'quantity' => $quantity, 'unit_price_centavos' => 100, 'unit_cost_centavos' => 0,
            'line_total_centavos' => $quantity * 100,
        ]);
    };
    for ($i = 1; $i <= 6; $i++) {
        $products[] = $product = $this->createProduct($this->store, ['name' => 'Ranked '.$i]);
        $addLine(dashboardSale($this->store, $this->owner), $product, $i);
    }
    $addLine(dashboardSale($this->store, $this->owner), $products[0], 10);
    $addLine(dashboardSale($this->store, $this->owner, ['status' => 'voided']), $products[1], 1000);
    $addLine(dashboardSale($this->store, $this->owner, ['completed_at' => '2026-10-01 00:00:00']), $products[1], 1000);
    $response = $this->getJson($this->base.'/dashboard')->assertOk()->assertJsonCount(5, 'data.top_products');
    expect(array_column($response->json('data.top_products'), 'units_sold'))->toBe(['11', '6', '5', '4', '3']);
    expect(array_column($response->json('data.top_products'), 'name'))->toBe(['Ranked 1', 'Ranked 6', 'Ranked 5', 'Ranked 4', 'Ranked 3']);
});

it('scopes every dashboard panel and rejects a foreign store', function () {
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $other = $this->createOwner();
    $foreign = $this->ownerStore($other);
    $this->actingAs($other, 'web');
    $product = $this->createProduct($foreign, ['initial_stock' => 0]);
    $sale = dashboardSale($foreign, $other, ['total_centavos' => 50000]);
    $sale->items()->create([
        'store_id' => $foreign->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Private product',
        'unit_snapshot' => 'piece', 'quantity' => 10, 'unit_price_centavos' => 5000,
        'unit_cost_centavos' => 0, 'line_total_centavos' => 50000,
    ]);
    $customer = $foreign->customers()->create(['name' => 'Private customer', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64)]);
    $customer->forceFill(['balance_centavos' => 123456])->save();
    $customer->ledgerEntries()->create([
        'store_id' => $foreign->id, 'actor_user_id' => $other->id, 'entry_type' => 'payment', 'payment_method' => 'cash',
        'amount_centavos' => 999, 'balance_before_centavos' => 999, 'balance_after_centavos' => 0,
    ]);
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $this->getJson('/api/v1/stores/'.$foreign->id.'/dashboard')->assertNotFound();
    $this->getJson($this->base.'/dashboard')->assertOk()
        ->assertJsonPath('data.today.transactions', 0)
        ->assertJsonPath('data.today.repayments_centavos', '0')
        ->assertJsonPath('data.credit.outstanding_centavos', '0')
        ->assertJsonPath('data.stock.active_products', 0)
        ->assertJsonPath('data.stock.alerts', [])
        ->assertJsonPath('data.top_products', [])
        ->assertJsonPath('data.recent_sales', []);
});

it('requires authentication', function () {
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->getJson($this->base.'/dashboard')->assertUnauthorized();
});

it('does not grant private dashboard access to platform admins without membership', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->actingAs($admin, 'web')->getJson($this->base.'/dashboard')->assertNotFound();
});

it('enforces active owner and store access', function ($kind, $status) {
    match ($kind) {
        'user' => $this->owner->forceFill(['status' => 'suspended'])->save(),
        'store' => $this->store->update(['status' => 'suspended']),
        'account' => $this->store->account->update(['status' => 'suspended']),
        'membership' => $this->owner->memberships()->update(['status' => 'inactive']),
        'staff' => $this->owner->memberships()->update(['role' => 'staff']),
    };
    $this->getJson($this->base.'/dashboard')->assertStatus($status);
})->with([['user', 403], ['store', 403], ['account', 403], ['membership', 403], ['staff', 403]]);

it('rejects invalid chart periods', function ($query) {
    $this->getJson($this->base.'/dashboard?'.$query)->assertUnprocessable()->assertJsonValidationErrors('period');
})->with(['period=', 'period=all_time', 'period[]=this_week']);
