<?php

use App\Models\User;
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
    $this->input = ['name' => '  Aling Linda  ', 'phone' => ' 09123456789 ', 'notes' => ' Suki ', 'request_key' => (string) Str::uuid()];
});

it('creates a normalized customer with zero balance and safe repeated saves', function () {
    $id = $this->postJson($this->base.'/customers', $this->input)->assertCreated()->assertJsonPath('data.name', 'Aling Linda')->assertJsonPath('data.phone', '09123456789')->assertJsonPath('data.balance_centavos', '0')->json('data.id');
    $this->input['request_key'] = strtoupper($this->input['request_key']);
    $this->postJson($this->base.'/customers', $this->input)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('meta.replayed', true);
    $this->input['name'] = 'Different';
    $this->postJson($this->base.'/customers', $this->input)->assertConflict();
    $this->assertDatabaseCount('customers', 1);
    $this->getJson($this->base.'/customers/'.$id.'/ledger')->assertOk()->assertJsonCount(0, 'data');
});

it('supports searchable paginated customer lists and store-wide balance summaries', function () {
    $this->postJson($this->base.'/customers', $this->input)->assertCreated();
    $this->input = ['name' => 'Juan', 'phone' => null, 'request_key' => (string) Str::uuid()];
    $this->postJson($this->base.'/customers', $this->input)->assertCreated();
    $this->getJson($this->base.'/customers?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.summary.customers', 2);
    $this->getJson($this->base.'/customers?q=091234')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Aling Linda');
    $this->getJson($this->base.'/customers?q=Juan')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->base.'/customers?balance=outstanding')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->base.'/customers?balance=settled')->assertOk()->assertJsonCount(2, 'data');
});

it('isolates customers and their ledger from another owner including credit checkout', function () {
    $customer = $this->postJson($this->base.'/customers', $this->input)->assertCreated()->json('data.id');
    $this->app['session']->flush();
    $this->resetBrowserGuards();
    $this->actingAs($this->other, 'web');
    $base = '/api/v1/stores/'.$this->foreign->id;
    $this->getJson($this->base.'/customers')->assertNotFound();
    $this->postJson($this->base.'/customers', $this->input)->assertNotFound();
    $this->getJson($base.'/customers/'.$customer.'/ledger')->assertNotFound();
    $this->getJson($base.'/customers')->assertOk()->assertJsonCount(0, 'data');
    $product = $this->createProduct($this->foreign);
    $input = $this->checkoutData($product);
    $input['payment'] = ['method' => 'credit', 'customer_id' => $customer];
    $this->postJson($base.'/sales', $input)->assertNotFound();
    expect($product->inventory->quantity_on_hand)->toBe(10);
    $this->assertDatabaseCount('sales', 0);
});

it('requires an active authenticated store owner', function () {
    $this->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->getJson($this->base.'/customers')->assertUnauthorized();
    $this->postJson($this->base.'/customers', $this->input)->assertUnauthorized();
    $admin = User::factory()->create();
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();
    $this->actingAs($admin, 'web')->getJson($this->base.'/customers')->assertNotFound();
});

it('rejects invalid customer data and arbitrary starting balances', function (array $override) {
    $this->postJson($this->base.'/customers', array_replace($this->input, $override))->assertUnprocessable();
    $this->assertDatabaseCount('customers', 0);
})->with([
    [['name' => '   ']], [['name' => str_repeat('a', 121)]], [['phone' => str_repeat('1', 31)]],
    [['notes' => str_repeat('a', 501)]], [['request_key' => 'bad']], [['balance_centavos' => 500]], [['store_id' => 1]], [['status' => 'archived']],
]);
