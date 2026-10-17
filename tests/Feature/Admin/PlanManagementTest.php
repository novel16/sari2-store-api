<?php

use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Admin\SavePlan;
use App\Services\Auth\RegisterStoreOwner;
use Database\Seeders\PlanSeeder;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
});

function managedPlanInput(array $overrides = []): array
{
    return array_replace(['code' => 'starter', 'name' => 'Starter', 'description' => 'A small store plan.',
        'monthly_price_centavos' => 7900, 'active_product_limit' => 600, 'quota_scope' => 'store',
        'store_limit' => 1, 'staff_limit' => 0, 'catalog_status' => 'draft', 'planned_features' => [],
        'featured' => false, 'sort_order' => 50, 'change_note' => 'Initial draft'], $overrides);
}

it('seeds the agreed catalog without creating users stores or subscriptions', function () {
    expect(Plan::orderBy('sort_order')->pluck('monthly_price_centavos', 'code')->all())->toBe(['free' => 0, 'basic' => 4900, 'plus' => 14900, 'pro' => 24900]);
    expect(Plan::orderBy('sort_order')->pluck('active_product_limit')->all())->toBe([100, 500, 1000, 3000]);
    expect(Plan::where('code', 'pro')->first()->quota_scope)->toBe('account');
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('stores', 0);
    $this->assertDatabaseCount('users', 1);
    expect(Plan::where('catalog_status', 'published')->pluck('code')->all())->toBe(['free', 'basic']);
});

it('keeps seeding idempotent and preserves managed changes', function () {
    $plan = Plan::where('code', 'basic')->firstOrFail();
    app(SavePlan::class)->handle(['name' => 'Basic updated', 'expected_version' => 1], $plan, $this->admin->id);
    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);
    expect($plan->fresh()->name)->toBe('Basic updated');
    $this->assertDatabaseCount('plans', 4);
    $this->assertDatabaseCount('plan_versions', 5);
});

it('protects all plan reads and writes from guests and owners', function () {
    $plan = Plan::firstOrFail();
    $this->getJson('/api/v1/admin/plans')->assertUnauthorized();
    $this->postJson('/api/v1/admin/plans', managedPlanInput())->assertUnauthorized();
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->actingAs($owner, 'web');
    $this->getJson('/api/v1/admin/plans/'.$plan->id)->assertForbidden();
    $this->getJson('/api/v1/admin/plans/'.$plan->id.'/versions')->assertForbidden();
    $this->postJson('/api/v1/admin/plans', managedPlanInput())->assertForbidden();
    $this->patchJson('/api/v1/admin/plans/'.$plan->id, managedPlanInput())->assertForbidden();
});

it('creates and versions plans with a server-selected audit actor', function () {
    $this->actingAs($this->admin, 'web');
    $id = $this->postJson('/api/v1/admin/plans', managedPlanInput())->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
    $this->patchJson('/api/v1/admin/plans/'.$id, managedPlanInput(['name' => 'Starter revised', 'expected_version' => 1, 'change_note' => 'Increase capacity', 'active_product_limit' => 750]))
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.active_product_limit', 750);
    $this->getJson('/api/v1/admin/plans/'.$id.'/versions')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.actor.id', $this->admin->id)->assertJsonPath('data.1.terms.active_product_limit', 600);
    $this->deleteJson('/api/v1/admin/plans/'.$id)->assertStatus(405);
});

it('rejects stale edits without creating extra versions', function () {
    $this->actingAs($this->admin, 'web');
    $id = $this->postJson('/api/v1/admin/plans', managedPlanInput())->assertCreated()->json('data.id');
    $input = managedPlanInput(['expected_version' => 1]);
    $this->patchJson('/api/v1/admin/plans/'.$id, $input)->assertOk();
    $this->patchJson('/api/v1/admin/plans/'.$id, [...$input, 'name' => 'Stale overwrite'])->assertConflict();
    expect(Plan::findOrFail($id)->name)->toBe('Starter');
    expect(PlanVersion::where('plan_id', $id)->count())->toBe(2);
});

it('rejects invalid prices unsafe publication and injected fields', function (array $changes, string $field) {
    $this->actingAs($this->admin, 'web')->postJson('/api/v1/admin/plans', managedPlanInput($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['monthly_price_centavos' => -1], 'monthly_price_centavos'],
    [['monthly_price_centavos' => 0], 'monthly_price_centavos'],
    [['monthly_price_centavos' => 49.5], 'monthly_price_centavos'],
    [['code' => '../bad'], 'code'], [['active_product_limit' => 0], 'active_product_limit'],
    [['catalog_status' => 'published', 'staff_limit' => 2], 'catalog_status'],
    [['catalog_status' => 'published', 'store_limit' => 3], 'catalog_status'],
    [['catalog_status' => 'published', 'planned_features' => ['Offline sales']], 'catalog_status'],
    [['actor_user_id' => 999], 'actor_user_id'], [['is_active' => false], 'is_active'],
]);

it('keeps Free published and free and plan codes immutable', function () {
    $free = Plan::where('code', 'free')->firstOrFail();
    $input = managedPlanInput([...$free->only(['code', 'name', 'monthly_price_centavos', 'active_product_limit']), 'expected_version' => $free->version, 'catalog_status' => 'published']);
    $this->actingAs($this->admin, 'web');
    $this->patchJson('/api/v1/admin/plans/'.$free->id, [...$input, 'catalog_status' => 'retired'])->assertUnprocessable()->assertJsonValidationErrors('catalog_status');
    $this->patchJson('/api/v1/admin/plans/'.$free->id, [...$input, 'monthly_price_centavos' => 100])->assertUnprocessable()->assertJsonValidationErrors('monthly_price_centavos');
    $this->patchJson('/api/v1/admin/plans/'.$free->id, [...$input, 'code' => 'renamed'])->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('keeps plan history immutable', function () {
    $version = PlanVersion::firstOrFail();
    expect(fn () => $version->update(['change_note' => 'Rewrite history']))->toThrow(LogicException::class);
});

it('keeps plan list queries constant as the catalog grows', function () {
    $this->actingAs($this->admin, 'web');
    $small = $this->readQueries(fn () => $this->getJson('/api/v1/admin/plans')->assertOk()->assertJsonCount(4, 'data'));
    for ($index = 0; $index < 20; $index++) {
        Plan::create(managedPlanInput(['code' => 'draft-'.$index]));
    }
    $large = $this->readQueries(fn () => $this->getJson('/api/v1/admin/plans')->assertOk()->assertJsonCount(20, 'data'));
    expect(count($large))->toBe(count($small));
});
