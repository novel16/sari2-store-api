<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->other = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->foreign = $this->ownerStore($this->other);
    $this->actingAs($this->owner, 'web');
    $this->base = '/api/v1/stores/'.$this->store->id;
    $this->product = $this->createProduct($this->store);
});

afterEach(fn () => Carbon::setTestNow());

it('paginates and searches saved sales with authoritative filtered totals', function () {
    $first = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()->json('data');
    $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated();
    $this->getJson($this->base.'/sales?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.summary.net_sales_centavos', '5800')->assertJsonPath('meta.summary.cash_collected_centavos', '5800');
    $this->getJson($this->base.'/sales?q='.$first['sale_number'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.transactions', 1);
    $this->getJson($this->base.'/sales?q=missing')->assertOk()->assertJsonCount(0, 'data');
});

it('uses Manila day boundaries and accepts open-ended date filters', function () {
    Carbon::setTestNow('2026-10-15 15:59:59 UTC');
    $first = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()->json('data.id');
    Carbon::setTestNow('2026-10-15 16:00:00 UTC');
    $second = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()->json('data.id');
    $this->getJson($this->base.'/sales?date_from=2026-10-15&date_to=2026-10-15')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
    $this->getJson($this->base.'/sales?date_from=2026-10-16&date_to=2026-10-16')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
    $this->getJson($this->base.'/sales?date_to=2026-10-15')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->base.'/sales?date_from=2026-10-17&date_to=2026-10-15')->assertUnprocessable();
});

it('keeps paginated exports consistent while new sales arrive', function () {
    $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated();
    $second = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()->json('data.id');
    $snapshot = $this->getJson($this->base.'/sales?per_page=1')->assertOk()->json('meta.snapshot_id');
    expect($snapshot)->toBe($second);
    $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated();
    $this->getJson($this->base.'/sales?per_page=1&page=2&through_id='.$snapshot)->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.summary.net_sales_centavos', '5800');
    $this->getJson($this->base.'/sales')->assertOk()->assertJsonPath('meta.total', 3);
});

it('refreshes selected cart products without including other store records', function () {
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->other, 'web');
    $foreign = $this->createProduct($this->foreign);
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $this->getJson($this->base.'/products?status=all&ids[]='.$this->product->id.'&ids[]='.$foreign->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->product->id);
    $this->getJson($this->base.'/products?ids[]=invalid')->assertUnprocessable();
});

it('does not expose completed sales for mutation or removal', function () {
    $id = $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertCreated()->json('data.id');
    $this->patchJson($this->base.'/sales/'.$id, ['total_centavos' => 1])->assertStatus(405);
    $this->deleteJson($this->base.'/sales/'.$id)->assertStatus(405);
    $this->getJson($this->base.'/sales/'.$id)->assertOk()->assertJsonPath('data.total_centavos', 2900);
});

it('protects all sales reads and checkout writes from another store owner', function () {
    $input = $this->checkoutData($this->product);
    $sale = $this->postJson($this->base.'/sales', $input)->assertCreated()->json('data.id');
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->other, 'web');
    $ownBase = '/api/v1/stores/'.$this->foreign->id;
    $this->getJson($this->base.'/sales')->assertNotFound();
    $this->getJson($this->base.'/sales/'.$sale)->assertNotFound();
    $this->postJson($this->base.'/sales', $input)->assertNotFound();
    $this->getJson($ownBase.'/sales/'.$sale)->assertNotFound();
    $this->getJson($ownBase.'/sales/by-key/'.$input['idempotency_key'])->assertNotFound();
    $this->postJson($ownBase.'/sales', $input)->assertNotFound();
    $this->getJson($ownBase.'/sales')->assertOk()->assertJsonCount(0, 'data');
    $this->assertDatabaseCount('sales', 1);
});

it('rejects a mixed cart containing another stores product without changing owned stock', function () {
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->other, 'web');
    $otherProduct = $this->createProduct($this->foreign);
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $input = $this->checkoutData($this->product);
    $input['items'][] = ['product_id' => $otherProduct->id, 'quantity' => 1];
    $this->postJson($this->base.'/sales', $input)->assertNotFound();
    expect($this->product->inventory->quantity_on_hand)->toBe(10)->and($otherProduct->inventory->quantity_on_hand)->toBe(10);
    $this->assertDatabaseCount('sales', 0);
});

it('requires authentication and does not grant platform admins automatic checkout access', function () {
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->getJson($this->base.'/sales')->assertUnauthorized();
    $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertUnauthorized();
    $admin = User::factory()->create();
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->actingAs($admin, 'web')->getJson($this->base.'/sales')->assertNotFound();
    $this->postJson($this->base.'/sales', $this->checkoutData($this->product))->assertNotFound();
});

it('returns generic not-found responses for missing checkout keys', function () {
    $this->getJson($this->base.'/sales/by-key/'.Str::uuid())->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
    $this->getJson($this->base.'/sales/by-key/not-a-uuid')->assertNotFound();
});
