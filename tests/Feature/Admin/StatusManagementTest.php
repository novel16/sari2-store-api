<?php

use App\Models\Admin\StatusChange;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\Status\StatusChangeService;
use App\Services\Auth\RegisterStoreOwner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->store = $this->ownerStore($this->owner);
    $this->account = $this->store->account;
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
});

function statusBase($test, string $type): string
{
    return '/api/v1/admin/'.($type === 'account' ? 'accounts/'.$test->account->id : 'stores/'.$test->store->id);
}

function reviewStatus($test, string $base, string $status = 'suspended', string $reason = 'Verified support request'): array
{
    return $test->postJson($base.'/status-preview', ['status' => $status, 'reason' => $reason])->assertOk()->json('data');
}

function confirmStatus($test, string $base, array $review, ?string $key = null)
{
    return $test->postJson($base.'/status-changes', [
        'request_key' => $key ?? (string) Str::uuid(), 'review_token' => $review['review_token'],
    ]);
}

it('restricts status review mutation recovery and audit history to active platform administrators', function (string $type) {
    $base = statusBase($this, $type);
    $key = (string) Str::uuid();
    foreach (['guest', 'owner', 'suspended administrator'] as $actor) {
        if ($actor === 'owner') {
            $this->resetBrowserGuards();
            $this->actingAs($this->owner, 'web');
        } elseif ($actor === 'suspended administrator') {
            $this->admin->forceFill(['status' => 'suspended'])->save();
            $this->resetBrowserGuards();
            $this->actingAs($this->admin, 'web');
        }
        $expected = $actor === 'guest' ? 401 : 403;
        $this->postJson($base.'/status-preview', ['status' => 'suspended', 'reason' => 'Support review'])->assertStatus($expected);
        $this->postJson($base.'/status-changes', [])->assertStatus($expected);
        $this->getJson($base.'/status-history')->assertStatus($expected);
        $this->getJson($base.'/status-changes/by-key/'.$key)->assertStatus($expected);
    }
    $this->assertDatabaseCount('admin_status_changes', 0);
})->with(['account', 'store']);

it('requires review and records exact reviewed status reason and immutable actor identity', function (string $type) {
    $base = statusBase($this, $type);
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $this->postJson($base.'/status-changes', ['request_key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('review_token');
    $review = reviewStatus($this, $base, 'suspended', '  Support verified temporary closure  ');
    expect($review['target_type'])->toBe($type);
    expect($review['from_status'])->toBe('active');
    expect($review['to_status'])->toBe('suspended');
    expect($review['reason'])->toBe('Support verified temporary closure');
    expect($review['impact']['child_statuses_preserved'])->toBeTrue();
    $this->assertDatabaseCount('admin_status_changes', 0);
    expect(($type === 'account' ? $this->account : $this->store)->fresh()->status)->toBe('active');
    // The display object is not trusted input: confirmation accepts only its signed token.
    $review['to_status'] = 'active';
    $review['reason'] = 'Client-edited reason';
    confirmStatus($this, $base, $review)->assertCreated()->assertJsonPath('data.target_type', $type)
        ->assertJsonPath('data.from_status', 'active')->assertJsonPath('data.to_status', 'suspended')
        ->assertJsonPath('data.reason', 'Support verified temporary closure')
        ->assertJsonPath('data.actor.id', $this->admin->id)->assertJsonPath('data.actor.name', $this->admin->name);
    expect(($type === 'account' ? $this->account : $this->store)->fresh()->status_revision)->toBe(1);
    $entry = StatusChange::sole();
    expect(fn () => $entry->update(['reason' => 'Rewritten']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
    $this->getJson($base.'/status-history')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.request_hash')->assertJsonMissingPath('data.0.review_hash')
        ->assertJsonMissingPath('data.0.review_token');
})->with(['account', 'store']);

it('requires a bounded reason and only supported status values', function (array $input, string $field) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    foreach (['account', 'store'] as $type) {
        $this->postJson(statusBase($this, $type).'/status-preview', array_replace([
            'status' => 'suspended', 'reason' => 'Support verified request',
        ], $input))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->assertDatabaseCount('admin_status_changes', 0);
})->with([
    [['reason' => null], 'reason'], [['reason' => '  '], 'reason'], [['reason' => 'ab'], 'reason'],
    [['reason' => str_repeat('x', 501)], 'reason'], [['reason' => ['invalid']], 'reason'],
    [['status' => 'deleted'], 'status'], [['status' => null], 'status'],
    [['actor_user_id' => 999], 'actor_user_id'], [['status_revision' => 999], 'status_revision'],
    [['account_id' => 999], 'account_id'],
]);

it('blocks every owner workspace immediately and preserves individually suspended stores on account reactivation', function () {
    $branch = Store::create(['account_id' => $this->account->id, 'name' => 'Suspended branch', 'slug' => 'branch', 'status' => 'suspended']);
    $otherOwner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $otherStore = $this->ownerStore($otherOwner);
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web')->getJson('/api/v1/stores/'.$this->store->id.'/products')->assertOk();
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, 'account');
    $review = reviewStatus($this, $base);
    expect($review['impact'])->toMatchArray(['affected_store_count' => 2, 'active_store_count' => 1, 'suspended_store_count' => 1]);
    confirmStatus($this, $base, $review)->assertCreated();
    expect($this->store->fresh()->status)->toBe('active');
    expect($branch->fresh()->status)->toBe('suspended');
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    foreach (['', '/products', '/customers', '/sales', '/inventory', '/reports/sales', '/cash-days/current'] as $path) {
        $this->getJson('/api/v1/stores/'.$this->store->id.$path)->assertForbidden();
    }
    $this->postJson('/api/v1/stores/'.$this->store->id.'/categories', ['name' => 'Blocked category'])->assertForbidden();
    $this->getJson('/api/v1/me')->assertForbidden()->assertJsonMissingPath('data')
        ->assertJsonMissingPath('reason')->assertJsonMissingPath('status_history');
    $this->resetBrowserGuards();
    $this->actingAs($otherOwner, 'web')->getJson('/api/v1/stores/'.$otherStore->id.'/products')->assertOk();
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    confirmStatus($this, $base, reviewStatus($this, $base, 'active', 'Support approved restoration'))->assertCreated();
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $this->getJson('/api/v1/stores/'.$this->store->id.'/products')->assertOk();
    $this->getJson('/api/v1/stores/'.$branch->id.'/products')->assertForbidden();
    expect($branch->fresh()->status)->toBe('suspended');
    expect($this->account->fresh()->status_revision)->toBe(2);
});

it('suspends only the selected store and requires its parent account active before restoring it', function () {
    $branch = Store::create(['account_id' => $this->account->id, 'name' => 'Active branch', 'slug' => 'branch', 'status' => 'active']);
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, 'store');
    confirmStatus($this, $base, reviewStatus($this, $base))->assertCreated();
    $this->resetBrowserGuards();
    $this->actingAs($this->owner, 'web');
    $this->getJson('/api/v1/stores/'.$this->store->id.'/products')->assertForbidden();
    $this->getJson('/api/v1/stores/'.$branch->id.'/products')->assertOk();
    expect($this->account->fresh()->status)->toBe('active');
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $restore = reviewStatus($this, $base, 'active');
    $accountBase = statusBase($this, 'account');
    confirmStatus($this, $accountBase, reviewStatus($this, $accountBase))->assertCreated();
    confirmStatus($this, $base, $restore)->assertConflict()->assertJsonPath('code', 'status_review_stale');
    $this->postJson($base.'/status-preview', ['status' => 'active', 'reason' => 'Restore branch'])
        ->assertConflict()->assertJsonPath('code', 'status_parent_suspended');
    expect($this->store->fresh()->status)->toBe('suspended');
});

it('replays the same reviewed request exactly once after expiry and subsequent status changes', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    $review = reviewStatus($this, $base);
    $key = (string) Str::uuid();
    $id = confirmStatus($this, $base, $review, $key)->assertCreated()->json('data.id');
    confirmStatus($this, $base, reviewStatus($this, $base, 'active'))->assertCreated();
    $this->travel(11)->minutes();
    confirmStatus($this, $base, $review, strtoupper($key))->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $id);
    $this->getJson($base.'/status-changes/by-key/'.strtoupper($key))->assertOk()->assertJsonPath('data.id', $id);
    confirmStatus($this, $base, $review)->assertConflict()->assertJsonPath('code', 'status_review_used');
    $fresh = reviewStatus($this, $base);
    confirmStatus($this, $base, $fresh, $key)->assertConflict()->assertJsonPath('code', 'status_key_conflict');
    expect(($type === 'account' ? $this->account : $this->store)->fresh()->status)->toBe('active');
    $this->assertDatabaseCount('admin_status_changes', 2);
})->with(['account', 'store']);

it('rejects expired reviews without changing status', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    $review = reviewStatus($this, $base);
    $this->travel(10)->minutes();
    confirmStatus($this, $base, $review)->assertConflict()->assertJsonPath('code', 'status_review_expired');
    $this->assertDatabaseCount('admin_status_changes', 0);
    expect(($type === 'account' ? $this->account : $this->store)->fresh()->status)->toBe('active');
})->with(['account', 'store']);

it('rejects competing reviews even when the target returns to its original status', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    $old = reviewStatus($this, $base);
    confirmStatus($this, $base, reviewStatus($this, $base))->assertCreated();
    confirmStatus($this, $base, reviewStatus($this, $base, 'active'))->assertCreated();
    confirmStatus($this, $base, $old)->assertConflict()->assertJsonPath('code', 'status_review_stale');
    $this->assertDatabaseCount('admin_status_changes', 2);
})->with(['account', 'store']);

it('invalidates store review when the parent returns to its original status', function () {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, 'store');
    $review = reviewStatus($this, $base);
    $parent = statusBase($this, 'account');
    confirmStatus($this, $parent, reviewStatus($this, $parent))->assertCreated();
    confirmStatus($this, $parent, reviewStatus($this, $parent, 'active'))->assertCreated();
    confirmStatus($this, $base, $review)->assertConflict()->assertJsonPath('code', 'status_review_stale');
    expect($this->store->fresh()->status)->toBe('active');
});

it('invalidates changed target names and account impact counts before confirmation', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    $review = reviewStatus($this, $base);
    if ($type === 'account') {
        Store::create(['account_id' => $this->account->id, 'name' => 'New branch', 'slug' => 'new-branch', 'status' => 'active']);
    } else {
        $this->store->update(['name' => 'Renamed branch']);
    }
    confirmStatus($this, $base, $review)->assertConflict()->assertJsonPath('code', 'status_review_stale');
    $this->assertDatabaseCount('admin_status_changes', 0);
})->with(['account', 'store']);

it('binds a review and its recovery to the exact actor and target', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    $review = reviewStatus($this, $base);
    $otherOwner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $otherStore = $this->ownerStore($otherOwner);
    $otherBase = '/api/v1/admin/'.($type === 'account' ? 'accounts/'.$otherStore->account_id : 'stores/'.$otherStore->id);
    confirmStatus($this, $otherBase, $review)->assertConflict()->assertJsonPath('code', 'status_review_invalid');
    confirmStatus($this, $base, ['review_token' => $review['review_token'].'corrupt'])->assertConflict();
    $otherAdmin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->resetBrowserGuards();
    $this->actingAs($otherAdmin, 'web');
    confirmStatus($this, $base, $review)->assertConflict()->assertJsonPath('code', 'status_review_invalid');
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $key = (string) Str::uuid();
    confirmStatus($this, $base, $review, $key)->assertCreated();
    $this->getJson($otherBase.'/status-changes/by-key/'.$key)->assertNotFound();
    $this->resetBrowserGuards();
    $this->actingAs($otherAdmin, 'web')->getJson($base.'/status-changes/by-key/'.$key)->assertNotFound();
    $this->assertDatabaseCount('admin_status_changes', 1);
})->with(['account', 'store']);

it('rolls back the status and revision when immutable audit creation fails', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    $review = reviewStatus($this, $base);
    $target = $type === 'account' ? $this->account : $this->store;
    StatusChange::creating(fn () => throw new RuntimeException('Synthetic status audit failure'));
    try {
        expect(fn () => app(StatusChangeService::class)->change($target, $this->admin, [
            'request_key' => (string) Str::uuid(), 'review_token' => $review['review_token'],
        ]))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.StatusChange::class);
    }
    expect($target->fresh()->status)->toBe('active');
    expect($target->fresh()->status_revision)->toBe(0);
    $this->assertDatabaseCount('admin_status_changes', 0);
    confirmStatus($this, $base, $review)->assertCreated();
})->with(['account', 'store']);

it('uses immutable identity snapshots and constant queries as audit history grows', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    $name = ($type === 'account' ? $this->account : $this->store)->name;
    $actorName = $this->admin->name;
    $firstId = confirmStatus($this, $base, reviewStatus($this, $base))->assertCreated()->json('data.id');
    $small = $this->readQueries(fn () => $this->getJson($base.'/status-history')->assertOk());
    for ($i = 0; $i < 7; $i++) {
        confirmStatus($this, $base, reviewStatus($this, $base, $i % 2 === 0 ? 'active' : 'suspended'))->assertCreated();
    }
    $large = $this->readQueries(fn () => $this->getJson($base.'/status-history')->assertOk()->assertJsonCount(8, 'data'));
    expect(count($large))->toBe(count($small));
    $this->admin->update(['name' => 'Renamed administrator']);
    ($type === 'account' ? $this->account : $this->store)->update(['name' => 'Renamed target']);
    $history = $this->getJson($base.'/status-history?per_page=1&page=8')->assertOk()
        ->assertJsonPath('data.0.id', $firstId)->assertJsonPath('data.0.actor.name', $actorName)->json('data.0');
    expect($history['target_name'])->toBe($name);
})->with(['account', 'store']);

it('keeps platform administrators outside private store data even after status management', function () {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, 'store');
    confirmStatus($this, $base, reviewStatus($this, $base))->assertCreated();
    confirmStatus($this, $base, reviewStatus($this, $base, 'active'))->assertCreated();
    foreach (['products', 'customers', 'sales', 'inventory', 'cash-days/current'] as $path) {
        $this->getJson('/api/v1/stores/'.$this->store->id.'/'.$path)->assertNotFound();
    }
    expect($this->admin->memberships()->count())->toBe(0);
});

it('reports whether reactivation actually restores owner workspace access', function (string $type, string $restriction, bool $blocked) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    confirmStatus($this, $base, reviewStatus($this, $base))->assertCreated();
    if ($restriction === 'all stores suspended') {
        $this->store->update(['status' => 'suspended']);
    } elseif ($restriction === 'owner suspended') {
        $this->owner->forceFill(['status' => 'suspended'])->save();
    } elseif ($restriction === 'membership suspended') {
        $this->owner->memberships()->where('account_id', $this->account->id)->update(['status' => 'suspended']);
    }
    $review = reviewStatus($this, $base, 'active', 'Restore the target status after support review');
    expect($review['impact']['owner_access_blocked'])->toBe($blocked);
    // Restoring the target itself remains allowed; independent restrictions stay intact.
    confirmStatus($this, $base, $review)->assertCreated();
    $this->resetBrowserGuards();
    $this->actingAs($this->owner->fresh(), 'web');
    $this->getJson('/api/v1/stores/'.$this->store->id.'/products')->assertStatus($blocked ? 403 : 200);
})->with([
    'eligible account owner' => ['account', 'eligible', false],
    'eligible store owner' => ['store', 'eligible', false],
    'account keeps suspended children' => ['account', 'all stores suspended', true],
    'account owner suspended' => ['account', 'owner suspended', true],
    'store owner suspended' => ['store', 'owner suspended', true],
    'account owner membership suspended' => ['account', 'membership suspended', true],
    'store owner membership suspended' => ['store', 'membership suspended', true],
]);

it('requires a fresh reactivation review when owner eligibility changes', function (string $type) {
    $this->resetBrowserGuards();
    $this->actingAs($this->admin, 'web');
    $base = statusBase($this, $type);
    confirmStatus($this, $base, reviewStatus($this, $base))->assertCreated();
    $review = reviewStatus($this, $base, 'active');
    expect($review['impact']['owner_access_blocked'])->toBeFalse();
    $this->owner->forceFill(['status' => 'suspended'])->save();
    confirmStatus($this, $base, $review)->assertConflict()->assertJsonPath('code', 'status_review_stale');
    expect(($type === 'account' ? $this->account : $this->store)->fresh()->status)->toBe('suspended');
    $this->assertDatabaseCount('admin_status_changes', 1);
})->with(['account', 'store']);
