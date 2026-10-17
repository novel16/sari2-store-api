<?php

use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);

function reportSale(Store $store, User $owner, array $overrides = []): Sale
{
    return $store->sales()->create(array_replace([
        'sold_by_user_id' => $owner->id, 'sold_by_name_snapshot' => $owner->name,
        'store_name_snapshot' => $store->name, 'sale_number' => 'R-'.Str::uuid(),
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
    $this->path = $this->base.'/reports/sales?date_from=2026-10-12&date_to=2026-10-16';
});

afterEach(fn () => Carbon::setTestNow());

it('returns zero totals and every empty Manila day without sample products', function () {
    $response = $this->getJson($this->path)->assertOk()
        ->assertJsonPath('data.store.id', $this->store->id)
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.date_from', '2026-10-12')
        ->assertJsonPath('data.date_to', '2026-10-16')
        ->assertJsonPath('data.top_products', [])
        ->assertJsonPath('data.top_products_limit', 10)
        ->assertJsonCount(5, 'data.days');
    foreach ($response->json('data.summary') as $key => $value) {
        expect($value)->toBe(in_array($key, ['transactions', 'repayment_count', 'reversal_count']) ? 0 : '0');
    }
    expect(array_column($response->json('data.days'), 'date'))->toBe(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16']);
});

it('reconciles real cash and utang checkouts repayments discounts change and retry totals with Dashboard', function () {
    $product = $this->createProduct($this->store);
    $id = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Linda'])->assertCreated()->json('data.id');
    $cash = $this->checkoutData($product);
    $this->postJson($this->base.'/sales', $cash)->assertCreated();
    $this->postJson($this->base.'/sales', $cash)->assertOk();
    $credit = $this->checkoutData($product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $id];
    $this->postJson($this->base.'/sales', $credit)->assertCreated();
    $payment = ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 1000, 'expected_balance_centavos' => '2900'];
    $this->postJson($this->base.'/customers/'.$id.'/payments', $payment)->assertCreated();
    $this->postJson($this->base.'/customers/'.$id.'/payments', $payment)->assertOk();
    $report = $this->getJson($this->path)->assertOk()->assertJsonPath('data.summary.transactions', 2)
        ->assertJsonPath('data.summary.units_sold', '4')
        ->assertJsonPath('data.summary.gross_sales_centavos', '6000')
        ->assertJsonPath('data.summary.discounts_centavos', '200')
        ->assertJsonPath('data.summary.net_sales_centavos', '5800')
        ->assertJsonPath('data.summary.cash_sales_centavos', '2900')
        ->assertJsonPath('data.summary.credit_sales_centavos', '2900')
        ->assertJsonPath('data.summary.repayments_centavos', '1000')
        ->assertJsonPath('data.summary.repayment_count', 1)
        ->assertJsonPath('data.summary.cash_collected_centavos', '3900')
        ->assertJsonPath('data.top_products.0.gross_sales_centavos', '6000')->json('data');
    $dashboard = $this->getJson($this->base.'/dashboard')->assertOk()->json('data');
    foreach ($dashboard['today'] as $key => $value) {
        expect($report['summary'][$key])->toBe($value);
    }
    foreach ($report['summary'] as $key => $value) {
        $sum = array_sum(array_column($report['days'], $key));
        expect((string) $sum)->toBe((string) $value);
    }
    expect($dashboard['chart']['days'][4]['net_sales_centavos'])->toBe($report['days'][4]['net_sales_centavos']);
});

it('attributes old-debt repayment to its collection date and supports collection-only days', function () {
    $product = $this->createProduct($this->store);
    $id = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Old debt'])->assertCreated()->json('data.id');
    Carbon::setTestNow(Carbon::parse('2026-10-10 03:30:00', 'UTC'));
    $credit = $this->checkoutData($product);
    $credit['payment'] = ['method' => 'credit', 'customer_id' => $id];
    $this->postJson($this->base.'/sales', $credit)->assertCreated();
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:30:00', 'UTC'));
    $this->store->customers()->whereKey($id)->update(['status' => 'archived']);
    $this->postJson($this->base.'/customers/'.$id.'/payments', [
        'request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_centavos' => 1000, 'expected_balance_centavos' => '2900',
    ])->assertCreated();
    $this->getJson($this->path)->assertOk()->assertJsonPath('data.summary.net_sales_centavos', '0')
        ->assertJsonPath('data.summary.transactions', 0)->assertJsonPath('data.summary.cash_collected_centavos', '1000')
        ->assertJsonPath('data.days.4.repayments_centavos', '1000')->assertJsonPath('data.days.4.net_sales_centavos', '0')
        ->assertJsonPath('data.days.4.repayment_count', 1)->assertJsonPath('data.top_products', []);
    $this->getJson($this->base.'/reports/sales?date_from=2026-10-10&date_to=2026-10-10')->assertOk()
        ->assertJsonPath('data.summary.credit_sales_centavos', '2900')->assertJsonPath('data.summary.repayments_centavos', '0');
});

it('uses inclusive Manila start and exclusive next midnight for sales and collections', function () {
    $id = $this->postJson($this->base.'/customers', ['request_key' => (string) Str::uuid(), 'name' => 'Boundary'])->assertCreated()->json('data.id');
    $customer = $this->store->customers()->findOrFail($id);
    foreach (['2026-10-15 15:59:59', '2026-10-15 16:00:00', '2026-10-16 15:59:59', '2026-10-16 16:00:00'] as $timestamp) {
        reportSale($this->store, $this->owner, ['completed_at' => $timestamp]);
        $customer->ledgerEntries()->make([
            'store_id' => $this->store->id, 'actor_user_id' => $this->owner->id, 'entry_type' => 'payment', 'payment_method' => 'cash',
            'amount_centavos' => 200, 'balance_before_centavos' => 200, 'balance_after_centavos' => 0,
        ])->forceFill(['created_at' => $timestamp])->save();
    }
    $this->getJson($this->base.'/reports/sales?date_from=2026-10-16&date_to=2026-10-16')->assertOk()
        ->assertJsonPath('data.summary.transactions', 2)->assertJsonPath('data.summary.net_sales_centavos', '200')
        ->assertJsonPath('data.summary.repayments_centavos', '400')->assertJsonPath('data.summary.cash_collected_centavos', '600')
        ->assertJsonCount(1, 'data.days')->assertJsonPath('data.days.0.transactions', 2)
        ->assertJsonPath('data.days.0.cash_collected_centavos', '600');
});

it('counts free sales and exact very large unit totals and excludes incomplete sales', function () {
    reportSale($this->store, $this->owner, ['subtotal_centavos' => 100, 'discount_centavos' => 100, 'total_centavos' => 0, 'amount_paid_centavos' => 0, 'quantity_total' => 9007199254740993]);
    reportSale($this->store, $this->owner, ['quantity_total' => 10]);
    reportSale($this->store, $this->owner, ['status' => 'voided', 'total_centavos' => 9999]);
    $this->getJson($this->path)->assertOk()->assertJsonPath('data.summary.transactions', 2)
        ->assertJsonPath('data.summary.units_sold', '9007199254741003')
        ->assertJsonPath('data.summary.net_sales_centavos', '100')
        ->assertJsonPath('data.days.4.units_sold', '9007199254741003');
});

it('ranks ten products using saved quantities prices and names including archived products', function () {
    $sale = reportSale($this->store, $this->owner);
    $products = [];
    for ($i = 1; $i <= 11; $i++) {
        $product = $this->createProduct($this->store, ['name' => 'Product '.$i]);
        $products[] = $product;
        $sale->items()->create([
            'store_id' => $this->store->id, 'product_id' => $product->id,
            'product_name_snapshot' => 'Saved '.$i, 'unit_snapshot' => 'piece', 'quantity' => $i,
            'unit_price_centavos' => 1500, 'unit_cost_centavos' => 0, 'line_total_centavos' => $i * 1500,
        ]);
    }
    $products[10]->update(['name' => 'Renamed', 'price_centavos' => 999, 'status' => 'archived']);
    $void = reportSale($this->store, $this->owner, ['status' => 'voided']);
    $void->items()->create([
        'store_id' => $this->store->id, 'product_id' => $products[0]->id,
        'product_name_snapshot' => 'Excluded', 'unit_snapshot' => 'piece', 'quantity' => 9999,
        'unit_price_centavos' => 1, 'unit_cost_centavos' => 0, 'line_total_centavos' => 9999,
    ]);
    $this->getJson($this->path)->assertOk()->assertJsonCount(10, 'data.top_products')
        ->assertJsonPath('data.top_products.0.name', 'Saved 11')->assertJsonPath('data.top_products.0.units_sold', '11')
        ->assertJsonPath('data.top_products.0.gross_sales_centavos', '16500')
        ->assertJsonPath('data.top_products.9.name', 'Saved 2');
});

it('supports complete leap-year and year-boundary ranges', function ($from, $to, $count) {
    $this->getJson($this->base.'/reports/sales?date_from='.$from.'&date_to='.$to)->assertOk()
        ->assertJsonCount($count, 'data.days')->assertJsonPath('data.days.0.date', $from)
        ->assertJsonPath('data.days.'.($count - 1).'.date', $to);
})->with([['2028-01-01', '2028-12-31', 366], ['2026-12-30', '2027-01-02', 4]]);

it('never leaks other store sales products customers or collections', function () {
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $other = $this->createOwner();
    $foreign = $this->ownerStore($other);
    $this->actingAs($other, 'web');
    $product = $this->createProduct($foreign);
    $sale = reportSale($foreign, $other);
    $sale->items()->create([
        'store_id' => $foreign->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Foreign product',
        'unit_snapshot' => 'piece', 'quantity' => 10, 'unit_price_centavos' => 10, 'unit_cost_centavos' => 0, 'line_total_centavos' => 100,
    ]);
    $customer = $foreign->customers()->create(['name' => 'Private', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64)]);
    $customer->ledgerEntries()->create([
        'store_id' => $foreign->id, 'actor_user_id' => $other->id, 'entry_type' => 'payment', 'payment_method' => 'cash',
        'amount_centavos' => 100, 'balance_before_centavos' => 100, 'balance_after_centavos' => 0,
    ]);
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $this->getJson('/api/v1/stores/'.$foreign->id.'/reports/sales?date_from=2026-10-12&date_to=2026-10-16')->assertNotFound();
    $this->getJson($this->path.'&store_id='.$foreign->id)->assertOk()
        ->assertJsonPath('data.summary.transactions', 0)->assertJsonPath('data.summary.repayments_centavos', '0')
        ->assertJsonPath('data.top_products', [])->assertJsonPath('data.days.4.net_sales_centavos', '0');
});

it('requires authentication and does not grant platform admin access without owner membership', function () {
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->getJson($this->path)->assertUnauthorized();
    $admin = User::factory()->create();
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->actingAs($admin, 'web')->getJson($this->path)->assertNotFound();
});

it('enforces active user store account and owner membership', function ($kind) {
    match ($kind) {
        'user' => $this->owner->forceFill(['status' => 'suspended'])->save(),
        'store' => $this->store->update(['status' => 'suspended']),
        'account' => $this->store->account->update(['status' => 'suspended']),
        'membership' => $this->owner->memberships()->update(['status' => 'inactive']),
        'staff' => $this->owner->memberships()->update(['role' => 'staff']),
    };
    $this->getJson($this->path)->assertForbidden();
})->with(['user', 'store', 'account', 'membership', 'staff']);

it('rejects missing malformed inverted and excessive report ranges', function ($query) {
    $this->getJson($this->base.'/reports/sales?'.$query)->assertUnprocessable();
})->with([
    '', 'date_from=2026-10-16', 'date_to=2026-10-16',
    'date_from=not-a-date&date_to=2026-10-16',
    'date_from=2026-02-30&date_to=2026-10-16',
    'date_from[]=2026-10-16&date_to=2026-10-16',
    'date_from=2026-10-17&date_to=2026-10-16',
    'date_from=2028-01-01&date_to=2029-01-01',
    'date_from=1969-12-31&date_to=1970-01-01',
    'date_from=9999-01-01&date_to=9999-01-01',
]);
