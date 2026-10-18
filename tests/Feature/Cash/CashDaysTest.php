<?php

use App\Models\AccountMembership;
use App\Models\CashDay;
use App\Models\CashMovement;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Auth\RegisterStoreOwner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\CountsDatabaseQueries;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T02:00:00Z'));
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
    $this->cashBase = $this->base.'/cash-days';
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function cashOpen($test, array $overrides = [])
{
    return $test->postJson($test->cashBase, array_replace([
        'request_key' => (string) Str::uuid(), 'opening_cash_centavos' => 5000,
        'business_date' => now('Asia/Manila')->toDateString(),
    ], $overrides));
}

function cashMove($test, int $day, string $direction, int $amount, array $overrides = [])
{
    return $test->postJson($test->cashBase.'/'.$day.'/movements', array_replace([
        'request_key' => (string) Str::uuid(), 'direction' => $direction,
        'amount_centavos' => $amount, 'reason' => 'Cash drawer adjustment',
    ], $overrides));
}

function cashReview($test, int $day, int $counted, ?string $notes = null)
{
    return $test->postJson($test->cashBase.'/'.$day.'/close-preview', [
        'counted_cash_centavos' => $counted, 'notes' => $notes,
    ]);
}

function cashClose($test, int $day, array $review, array $overrides = [])
{
    return $test->postJson($test->cashBase.'/'.$day.'/close', array_replace([
        'request_key' => (string) Str::uuid(), 'review_token' => $review['review_token'],
    ], $overrides));
}

function cashProduct($test)
{
    $id = $test->postJson($test->base.'/products', ['name' => 'Cash test bottle', 'unit' => 'bottle',
        'price_centavos' => 1000, 'cost_centavos' => 500, 'initial_stock' => 50])
        ->assertCreated()->json('data.id');

    return Product::findOrFail($id);
}

function cashSale($test, $product, int $quantity = 1, ?int $customer = null)
{
    $input = ['idempotency_key' => (string) Str::uuid(), 'discount_centavos' => 0,
        'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        'payment' => ['method' => 'cash', 'tendered_centavos' => $quantity * 1000]];
    if ($customer !== null) {
        $input['payment'] = ['method' => 'credit', 'customer_id' => $customer];
    }

    return $test->postJson($test->base.'/sales', $input);
}

function cashCustomer($test, int $opening = 0): int
{
    $id = $test->postJson($test->base.'/customers', [
        'request_key' => (string) Str::uuid(), 'name' => 'Drawer customer',
    ])->assertCreated()->json('data.id');
    if ($opening > 0) {
        $test->postJson($test->base.'/customers/'.$id.'/opening-balance', [
            'request_key' => (string) Str::uuid(), 'amount_centavos' => $opening,
            'notes' => 'Existing notebook debt',
        ])->assertCreated();
    }

    return $id;
}

function cashRepay($test, int $customer, int $amount, string $balance)
{
    return $test->postJson($test->base.'/customers/'.$customer.'/payments', [
        'request_key' => (string) Str::uuid(), 'method' => 'cash',
        'amount_centavos' => $amount, 'expected_balance_centavos' => $balance,
    ]);
}

function cashRefund($test, int $sale, ?string $balance = null)
{
    return $test->postJson($test->base.'/sales/'.$sale.'/reversals', [
        'request_key' => (string) Str::uuid(), 'kind' => 'refund', 'reason' => 'Returned to store',
        'restock' => true, 'expected_balance_centavos' => $balance, 'cash_confirmed' => true,
    ]);
}

it('opens one audited drawer per store and Manila day with recoverable retries', function () {
    $this->getJson($this->cashBase.'/current')->assertOk()->assertJsonPath('data', null)
        ->assertJsonPath('totals', null)->assertJsonPath('business_date', '2026-10-16')
        ->assertJsonPath('time_zone', 'Asia/Manila');
    $key = (string) Str::uuid();
    $day = cashOpen($this, ['request_key' => $key])->assertCreated()
        ->assertJsonPath('data.business_date', '2026-10-16')->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.opening_cash_centavos', '5000')
        ->assertJsonPath('data.opened_by.id', $this->owner->id)->json('data.id');
    cashOpen($this, ['request_key' => strtoupper($key)])->assertOk()
        ->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $day);
    cashOpen($this, ['request_key' => $key, 'opening_cash_centavos' => 6000])->assertConflict()->assertJsonPath('code', 'cash_key_conflict');
    cashOpen($this)->assertConflict()->assertJsonPath('code', 'cash_day_exists');
    $this->getJson($this->cashBase.'/by-key/'.$key)->assertOk()->assertJsonPath('data.id', $day);
    $this->assertDatabaseCount('cash_days', 1);
});

it('requires the server-pinned opening date before attempting a new drawer', function () {
    $this->postJson($this->cashBase, ['request_key' => (string) Str::uuid(), 'opening_cash_centavos' => 5000])
        ->assertUnprocessable()->assertJsonValidationErrors('business_date');
    $this->assertDatabaseCount('cash_days', 0);
});

it('rejects invalid opening money and client-controlled audit fields', function (array $changes, string $field) {
    cashOpen($this, $changes)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('cash_days', 0);
})->with([
    [['opening_cash_centavos' => -1], 'opening_cash_centavos'],
    [['opening_cash_centavos' => 1.5], 'opening_cash_centavos'],
    [['opening_cash_centavos' => 1000000001], 'opening_cash_centavos'],
    [['notes' => str_repeat('x', 501)], 'notes'],
    [['store_id' => 999], 'store_id'], [['actor_user_id' => 999], 'actor_user_id'],
    [['business_date' => 'not-a-date'], 'business_date'], [['status' => 'closed'], 'status'],
]);

it('records audited cash in and out once and refuses an overdrawn drawer', function () {
    $day = cashOpen($this, ['opening_cash_centavos' => 100])->assertCreated()->json('data.id');
    cashMove($this, $day, 'out', 101)->assertConflict()->assertJsonPath('code', 'cash_insufficient_cash');
    $key = (string) Str::uuid();
    $movement = cashMove($this, $day, 'in', 500, ['request_key' => $key, 'reason' => '  Change fund  '])
        ->assertCreated()->assertJsonPath('data.amount_centavos', '500')
        ->assertJsonPath('data.reason', 'Change fund')->json('data.id');
    cashMove($this, $day, 'in', 500, ['request_key' => strtoupper($key), 'reason' => 'Change fund'])
        ->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $movement);
    cashMove($this, $day, 'in', 501, ['request_key' => $key, 'reason' => 'Change fund'])
        ->assertConflict()->assertJsonPath('code', 'cash_key_conflict');
    cashMove($this, $day, 'out', 600)->assertCreated();
    $this->getJson($this->cashBase.'/current')->assertOk()->assertJsonPath('totals.expected_cash_centavos', '0');
    $this->getJson($this->cashBase.'/movements/by-key/'.$key)->assertOk()->assertJsonPath('data.id', $movement);
    $this->assertDatabaseCount('cash_movements', 2);
});

it('validates manual movement money reasons and protected fields', function (array $changes, string $field) {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    cashMove($this, $day, 'in', 100, $changes)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('cash_movements', 0);
})->with([
    [['amount_centavos' => 0], 'amount_centavos'], [['amount_centavos' => 1.5], 'amount_centavos'],
    [['amount_centavos' => 1000000001], 'amount_centavos'], [['direction' => 'refund'], 'direction'],
    [['reason' => '  '], 'reason'], [['reason' => 'ab'], 'reason'],
    [['reason' => str_repeat('x', 256)], 'reason'], [['store_id' => 999], 'store_id'],
    [['cash_day_id' => 999], 'cash_day_id'], [['actor_user_id' => 999], 'actor_user_id'],
    [['business_date' => '2020-01-01'], 'business_date'],
]);

it('counts the full Manila day including pre-opening sales and excludes debt creation', function () {
    $product = cashProduct($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-15T23:00:00Z'));
    cashSale($this, $product)->assertCreated();
    $customer = cashCustomer($this, 10000);
    cashSale($this, $product, 2, $customer)->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-10-16T02:00:00Z'));
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $sale = cashSale($this, $product)->assertCreated()->json('data.id');
    cashRepay($this, $customer, 2500, '12000')->assertCreated();
    cashRefund($this, $sale)->assertCreated();
    cashMove($this, $day, 'in', 700)->assertCreated();
    cashMove($this, $day, 'out', 300)->assertCreated();
    $totals = ['opening_cash_centavos' => '5000', 'cash_sales_centavos' => '2000',
        'repayments_centavos' => '2500', 'cash_refunds_centavos' => '1000',
        'cash_in_centavos' => '700', 'cash_out_centavos' => '300', 'expected_cash_centavos' => '8900',
        'cash_sale_count' => 2, 'repayment_count' => 1, 'cash_refund_count' => 1, 'movement_count' => 2];
    $current = $this->getJson($this->cashBase.'/current')->assertOk();
    foreach ($totals as $key => $value) {
        $current->assertJsonPath('totals.'.$key, $value);
    }
    $review = cashReview($this, $day, 8900)->assertOk()->assertJsonPath('data.variance_centavos', '0')->json('data');
    $closed = cashClose($this, $day, $review)->assertCreated();
    foreach ($totals as $key => $value) {
        $closed->assertJsonPath('data.closing.totals.'.$key, $value);
    }
});

it('counts a zero-total cash sale without inventing cash or a zero-cash refund', function () {
    $product = $this->createProduct($this->store, ['price_centavos' => 0]);
    $day = cashOpen($this, ['opening_cash_centavos' => 0])->assertCreated()->json('data.id');
    $sale = cashSale($this, $product)->assertCreated()->json('data.id');
    cashRefund($this, $sale)->assertCreated()->assertJsonPath('reversal.cash_refund_centavos', 0);
    $this->getJson($this->cashBase.'/current')->assertOk()->assertJsonPath('totals.cash_sale_count', 1)
        ->assertJsonPath('totals.cash_sales_centavos', '0')->assertJsonPath('totals.cash_refund_count', 0)
        ->assertJsonPath('totals.expected_cash_centavos', '0');
    cashMove($this, $day, 'out', 1)->assertConflict()->assertJsonPath('code', 'cash_insufficient_cash');
});

it('requires a read-only explicit review and preserves its counted amount and variance explanation', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $before = CashDay::findOrFail($day)->getAttributes();
    $this->postJson($this->cashBase.'/'.$day.'/close', ['request_key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('review_token');
    cashReview($this, $day, 4900)->assertUnprocessable()->assertJsonValidationErrors('notes');
    cashReview($this, $day, 4900, 'ab')->assertUnprocessable()->assertJsonValidationErrors('notes');
    $review = cashReview($this, $day, 4900, '  Missing one coin  ')->assertOk()
        ->assertJsonPath('data.counted_cash_centavos', '4900')->assertJsonPath('data.variance_centavos', '-100')
        ->assertJsonPath('data.notes', 'Missing one coin')->json('data');
    expect(CashDay::findOrFail($day)->getAttributes())->toBe($before);
    $this->assertDatabaseCount('cash_movements', 0);
    cashClose($this, $day, $review, ['counted_cash_centavos' => 5000])
        ->assertUnprocessable()->assertJsonValidationErrors('counted_cash_centavos');
    cashClose($this, $day, $review)->assertCreated()->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.closed_by.id', $this->owner->id)
        ->assertJsonPath('data.closing.counted_cash_centavos', '4900')
        ->assertJsonPath('data.closing.variance_centavos', '-100')
        ->assertJsonPath('data.closing.notes', 'Missing one coin');
    $this->patchJson($this->cashBase.'/'.$day, ['status' => 'open'])->assertStatus(405);
});

it('invalidates a review after offsetting activity even when expected cash returns to the same amount', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $review = cashReview($this, $day, 5000)->assertOk()->json('data');
    cashMove($this, $day, 'in', 500)->assertCreated();
    cashMove($this, $day, 'out', 500)->assertCreated();
    cashClose($this, $day, $review)->assertConflict()->assertJsonPath('code', 'cash_activity_changed');
    $this->getJson($this->cashBase.'/'.$day)->assertOk()->assertJsonPath('data.status', 'open');
    cashClose($this, $day, cashReview($this, $day, 5000)->assertOk()->json('data'))->assertCreated();
});

it('validates counted cash and rejects injected review or closing state', function (array $changes, string $field) {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $this->postJson($this->cashBase.'/'.$day.'/close-preview', array_replace([
        'counted_cash_centavos' => 5000,
    ], $changes))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->getJson($this->cashBase.'/'.$day)->assertOk()->assertJsonPath('data.status', 'open');
})->with([
    [['counted_cash_centavos' => -1], 'counted_cash_centavos'],
    [['counted_cash_centavos' => 1.5], 'counted_cash_centavos'],
    [['counted_cash_centavos' => 1000000001], 'counted_cash_centavos'],
    [['notes' => str_repeat('x', 501)], 'notes'], [['totals' => ['expected_cash_centavos' => '1']], 'totals'],
    [['variance_centavos' => 0], 'variance_centavos'], [['actor_user_id' => 999], 'actor_user_id'],
    [['store_id' => 999], 'store_id'], [['request_key' => (string) Str::uuid()], 'request_key'],
]);

it('accepts only the reviewed token and request key at final close', function (array $changes, string $field) {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $review = cashReview($this, $day, 5000)->assertOk()->json('data');
    cashClose($this, $day, $review, $changes)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->getJson($this->cashBase.'/'.$day)->assertOk()->assertJsonPath('data.status', 'open');
})->with([
    [['notes' => 'Unreviewed change'], 'notes'], [['opening_cash_centavos' => 1], 'opening_cash_centavos'],
    [['closing' => ['counted_cash_centavos' => '1']], 'closing'], [['closed_by_user_id' => 999], 'closed_by_user_id'],
    [['business_date' => '2020-01-01'], 'business_date'], [['store_id' => 999], 'store_id'],
]);

it('rejects expired reviews but replays a saved closing after expiry and midnight', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $review = cashReview($this, $day, 5000)->assertOk()->json('data');
    $this->travel(11)->minutes();
    cashClose($this, $day, $review)->assertConflict()->assertJsonPath('code', 'cash_review_expired');
    $review = cashReview($this, $day, 5000)->assertOk()->json('data');
    $key = (string) Str::uuid();
    $saved = cashClose($this, $day, $review, ['request_key' => $key])->assertCreated()->json('data');
    $this->travel(1)->day();
    cashClose($this, $day, $review, ['request_key' => strtoupper($key)])->assertOk()
        ->assertJsonPath('meta.replayed', true)->assertJsonPath('data.closing', $saved['closing']);
    $this->getJson($this->cashBase.'/closings/by-key/'.$key)->assertOk()->assertJsonPath('data.closing', $saved['closing']);
    cashClose($this, $day, $review)->assertConflict();
    cashClose($this, $day, [...$review, 'review_token' => 'tampered'], ['request_key' => $key])
        ->assertConflict()->assertJsonPath('code', 'cash_key_conflict');
});

it('binds a closing review to its actor and store', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $review = cashReview($this, $day, 5000)->assertOk()->json('data');
    $other = User::factory()->create(['status' => 'active']);
    AccountMembership::create(['account_id' => $this->store->account_id, 'user_id' => $other->id, 'role' => 'owner', 'status' => 'active']);
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($other, 'web');
    cashClose($this, $day, $review)->assertConflict()->assertJsonPath('code', 'cash_review_invalid');
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $branch = Store::create(['account_id' => $this->store->account_id, 'name' => 'Other drawer', 'slug' => 'other-drawer', 'status' => 'active']);
    $branchBase = '/api/v1/stores/'.$branch->id.'/cash-days';
    $branchDay = $this->postJson($branchBase, ['request_key' => (string) Str::uuid(),
        'business_date' => '2026-10-16', 'opening_cash_centavos' => 5000])
        ->assertCreated()->json('data.id');
    $this->postJson($branchBase.'/'.$branchDay.'/close', ['request_key' => (string) Str::uuid(), 'review_token' => $review['review_token']])
        ->assertConflict()->assertJsonPath('code', 'cash_review_invalid');
    cashClose($this, $day, [...$review, 'review_token' => 'tampered'])->assertConflict()->assertJsonPath('code', 'cash_review_invalid');
});

it('uses UTC instants to split cash activity at Manila midnight and permits an overdue final close', function () {
    $product = cashProduct($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-15T15:59:59Z'));
    cashSale($this, $product)->assertCreated();
    $yesterdayKey = (string) Str::uuid();
    $yesterday = cashOpen($this, ['request_key' => $yesterdayKey, 'opening_cash_centavos' => 100])->assertCreated()->json('data.id');
    $movementKey = (string) Str::uuid();
    cashMove($this, $yesterday, 'in', 100, ['request_key' => $movementKey])->assertCreated();
    $review = cashReview($this, $yesterday, 1200)->assertOk()->json('data');
    $this->travelTo(CarbonImmutable::parse('2026-10-15T16:00:00Z'));
    cashSale($this, $product, 2)->assertCreated();
    cashOpen($this, ['business_date' => '2026-10-15'])->assertConflict()->assertJsonPath('code', 'cash_date_changed');
    $this->assertDatabaseCount('cash_days', 1);
    $today = cashOpen($this, ['opening_cash_centavos' => 200])->assertCreated()->assertJsonPath('data.business_date', '2026-10-16')->json('data.id');
    $this->getJson($this->cashBase.'/current')->assertOk()->assertJsonPath('totals.cash_sales_centavos', '2000')
        ->assertJsonPath('totals.expected_cash_centavos', '2200');
    cashClose($this, $today, $review)->assertConflict()->assertJsonPath('code', 'cash_review_invalid');
    cashMove($this, $yesterday, 'in', 100)->assertConflict()->assertJsonPath('code', 'cash_day_not_today');
    cashMove($this, $yesterday, 'in', 100, ['request_key' => $movementKey])->assertOk()->assertJsonPath('meta.replayed', true);
    cashOpen($this, ['request_key' => $yesterdayKey, 'business_date' => '2026-10-15', 'opening_cash_centavos' => 100])
        ->assertOk()->assertJsonPath('data.id', $yesterday);
    cashClose($this, $yesterday, cashReview($this, $yesterday, 1200)->assertOk()->json('data'))
        ->assertCreated()->assertJsonPath('data.closing.totals.cash_sales_centavos', '1000');
    $this->getJson($this->cashBase.'/'.$today)->assertOk()->assertJsonPath('data.status', 'open');
});

it('blocks same-day cash writes after closing with no side effects but permits credit and next-day cash', function () {
    $product = cashProduct($this);
    $customer = cashCustomer($this, 10000);
    $cash = cashSale($this, $product)->assertCreated()->json('data.id');
    $credit = cashSale($this, $product, 2, $customer)->assertCreated()->json('data.id');
    $day = cashOpen($this)->assertCreated()->json('data.id');
    cashClose($this, $day, cashReview($this, $day, 6000)->assertOk()->json('data'))->assertCreated();
    $stock = $product->inventory()->first()->quantity_on_hand;
    cashSale($this, $product)->assertConflict()->assertJsonPath('code', 'cash_day_closed');
    cashRepay($this, $customer, 1000, '12000')->assertConflict()->assertJsonPath('code', 'cash_day_closed');
    cashRefund($this, $cash)->assertConflict()->assertJsonPath('code', 'cash_day_closed');
    $this->assertDatabaseCount('sales', 2);
    $this->assertDatabaseCount('sale_payments', 1);
    $this->assertDatabaseCount('sale_reversals', 0);
    $this->assertDatabaseCount('credit_ledger_entries', 2);
    expect($product->inventory()->first()->quantity_on_hand)->toBe($stock)
        ->and($this->store->customers()->findOrFail($customer)->balance_centavos)->toBe(12000);
    cashSale($this, $product, 1, $customer)->assertCreated();
    cashRefund($this, $credit, '13000')->assertCreated()->assertJsonPath('reversal.cash_refund_centavos', 0);
    $this->travel(1)->day();
    $this->getJson($this->cashBase.'/current')->assertOk()->assertJsonPath('data', null);
    cashSale($this, $product)->assertCreated();
});

it('preserves the final closing snapshot and rejects further movement or a second final close', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $moveKey = (string) Str::uuid();
    cashMove($this, $day, 'in', 100, ['request_key' => $moveKey])->assertCreated();
    $review = cashReview($this, $day, 5100)->assertOk()->json('data');
    $saved = cashClose($this, $day, $review)->assertCreated()->json('data');
    cashMove($this, $day, 'out', 100)->assertConflict()->assertJsonPath('code', 'cash_day_closed');
    cashMove($this, $day, 'in', 100, ['request_key' => $moveKey])->assertOk()->assertJsonPath('meta.replayed', true);
    cashClose($this, $day, $review)->assertConflict()->assertJsonPath('code', 'cash_day_closed');
    $this->owner->update(['name' => 'Later owner name']);
    $this->store->update(['name' => 'Later store name']);
    $this->travel(1)->day();
    // Simulate a later repair/import bypassing model guards. Final reports must
    // still use the recorded closing, rather than reaggregating this old event.
    DB::table('cash_movements')->where('cash_day_id', $day)->update(['amount_centavos' => 999999]);
    $product = cashProduct($this);
    cashSale($this, $product)->assertCreated();
    $this->getJson($this->cashBase.'/'.$day)->assertOk()->assertJsonPath('data.closing', $saved['closing'])
        ->assertJsonPath('data.opened_by.name', $saved['opened_by']['name']);
    $this->getJson($this->cashBase)->assertOk()->assertJsonPath('data.0.closing', $saved['closing']);
    expect(fn () => CashDay::findOrFail($day)->update(['opening_cash_centavos' => 1]))->toThrow(LogicException::class);
    expect(fn () => CashDay::findOrFail($day)->delete())->toThrow(LogicException::class);
    expect(fn () => CashMovement::firstOrFail()->update(['reason' => 'Rewritten']))->toThrow(LogicException::class);
    expect(fn () => CashMovement::firstOrFail()->delete())->toThrow(LogicException::class);
});

it('does not expose a foreign cash day movement or recovery key to another owner admins or guests', function () {
    $openingKey = (string) Str::uuid();
    $day = cashOpen($this, ['request_key' => $openingKey])->assertCreated()->json('data.id');
    $moveKey = (string) Str::uuid();
    cashMove($this, $day, 'in', 100, ['request_key' => $moveKey])->assertCreated();
    $review = cashReview($this, $day, 5100)->assertOk()->json('data');
    $closeKey = (string) Str::uuid();
    cashClose($this, $day, $review, ['request_key' => $closeKey])->assertCreated();
    $foreign = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $foreignStore = $this->ownerStore($foreign);
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($foreign, 'web');
    $reads = ['/current', '', '/'.$day, '/'.$day.'/movements', '/by-key/'.$openingKey,
        '/movements/by-key/'.$moveKey, '/closings/by-key/'.$closeKey];
    $writes = [
        '' => ['request_key' => (string) Str::uuid(), 'business_date' => '2026-10-16', 'opening_cash_centavos' => 0],
        '/'.$day.'/movements' => ['request_key' => (string) Str::uuid(), 'direction' => 'in', 'amount_centavos' => 1, 'reason' => 'Foreign drawer'],
        '/'.$day.'/close-preview' => ['counted_cash_centavos' => 5100],
        '/'.$day.'/close' => ['request_key' => (string) Str::uuid(), 'review_token' => $review['review_token']],
    ];
    foreach ($reads as $path) {
        $this->getJson($this->cashBase.$path)->assertNotFound();
    }
    foreach ($writes as $path => $input) {
        $this->postJson($this->cashBase.$path, $input)->assertNotFound();
    }
    $ownBase = '/api/v1/stores/'.$foreignStore->id.'/cash-days';
    foreach (['/'.$day, '/'.$day.'/movements', '/by-key/'.$openingKey,
        '/movements/by-key/'.$moveKey, '/closings/by-key/'.$closeKey] as $path) {
        $this->getJson($ownBase.$path)->assertNotFound();
    }
    foreach ($writes as $path => $input) {
        if ($path !== '') {
            $this->postJson($ownBase.$path, $input)->assertNotFound();
        }
    }
    $admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($admin, 'web');
    foreach ($reads as $path) {
        $this->getJson($this->cashBase.$path)->assertNotFound();
    }
    foreach ($writes as $path => $input) {
        $this->postJson($this->cashBase.$path, $input)->assertNotFound();
    }
    $this->app['auth']->guard('web')->logout();
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    foreach ($reads as $path) {
        $this->getJson($this->cashBase.$path)->assertUnauthorized();
    }
    foreach ($writes as $path => $input) {
        $this->postJson($this->cashBase.$path, $input)->assertUnauthorized();
    }
});

it('rolls back a failed closing and allows the same reviewed operation to be retried', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    $review = cashReview($this, $day, 5000)->assertOk()->json('data');
    $key = (string) Str::uuid();
    CashDay::saving(function (CashDay $record) {
        if ($record->status === 'closed') {
            throw new RuntimeException('Simulated closing persistence failure');
        }
    });
    try {
        cashClose($this, $day, $review, ['request_key' => $key])->assertStatus(500);
    } finally {
        Event::forget('eloquent.saving: '.CashDay::class);
    }
    $this->getJson($this->cashBase.'/'.$day)->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.closing', null);
    $this->getJson($this->cashBase.'/closings/by-key/'.$key)->assertNotFound();
    cashClose($this, $day, $review, ['request_key' => $key])->assertCreated();
});

it('rolls back a failed manual movement without changing the drawer totals', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    CashMovement::creating(fn () => throw new RuntimeException('Simulated movement persistence failure'));
    try {
        cashMove($this, $day, 'out', 100)->assertStatus(500);
    } finally {
        Event::forget('eloquent.creating: '.CashMovement::class);
    }
    $this->assertDatabaseCount('cash_movements', 0);
    $this->getJson($this->cashBase.'/current')->assertOk()->assertJsonPath('totals.expected_cash_centavos', '5000');
});

it('keeps history and movement read query counts constant as rows accumulate', function () {
    $day = cashOpen($this)->assertCreated()->json('data.id');
    cashMove($this, $day, 'in', 100)->assertCreated();
    $small = $this->readQueries(fn () => $this->getJson($this->cashBase.'/'.$day.'/movements?per_page=100')->assertOk());
    for ($index = 0; $index < 12; $index++) {
        cashMove($this, $day, 'in', 100)->assertCreated();
    }
    $large = $this->readQueries(fn () => $this->getJson($this->cashBase.'/'.$day.'/movements?per_page=100')->assertOk()->assertJsonCount(13, 'data'));
    expect(count($large))->toBe(count($small));
    $small = $this->readQueries(fn () => $this->getJson($this->cashBase.'?per_page=100')->assertOk());
    for ($index = 0; $index < 6; $index++) {
        $this->travel(1)->day();
        $id = cashOpen($this)->assertCreated()->json('data.id');
        cashClose($this, $id, cashReview($this, $id, 5000)->assertOk()->json('data'))->assertCreated();
    }
    $large = $this->readQueries(fn () => $this->getJson($this->cashBase.'?per_page=100')->assertOk()->assertJsonCount(7, 'data'));
    expect(count($large))->toBe(count($small));
});
