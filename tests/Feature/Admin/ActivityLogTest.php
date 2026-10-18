<?php

use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Admin\SavePlan;
use App\Services\Auth\RegisterStoreOwner;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminActivityFixtures;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class, AdminActivityFixtures::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 18)->setTime(4, 0));
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active', 'name' => 'Current Actor']);
    $this->target = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active', 'name' => 'Current Target']);
    $this->owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $this->store = $this->ownerStore($this->owner);
    $this->account = $this->store->account;
    $this->actingAs($this->admin, 'web');
});

it('restricts centralized activity to current active platform administrators', function (string $actor) {
    $this->activitySources();
    $this->resetBrowserGuards();
    if ($actor === 'owner') {
        $this->actingAs($this->owner, 'web');
    } elseif ($actor === 'suspended administrator') {
        $this->admin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->admin, 'web');
    }
    $this->getJson($this->activityQuery())->assertStatus($actor === 'guest' ? 401 : 403);
})->with(['guest', 'owner', 'suspended administrator']);

it('rechecks a previously authenticated administrator after suspension', function () {
    $this->getJson($this->activityQuery())->assertOk();
    DB::table('users')->where('id', $this->admin->id)->update(['status' => 'suspended', 'admin_status_revision' => 1]);
    $this->getJson($this->activityQuery())->assertForbidden();
});

it('combines all immutable sources into a stable common shape with snapshot names and target links', function () {
    [$administrator, $account, $store, $plan, $subscription] = $this->activitySources();
    $this->admin->forceFill(['name' => 'Renamed Actor'])->save();
    $this->target->forceFill(['name' => 'Renamed Administrator'])->save();
    $this->account->update(['name' => 'Renamed Account']);
    $this->store->update(['name' => 'Renamed Store']);
    Plan::findOrFail($plan->plan_id)->update(['name' => 'Renamed Plan']);
    $rows = $this->getJson($this->activityQuery())->assertOk()->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.time_zone', 'Asia/Manila')->assertJsonPath('meta.per_page', 20)->json('data');
    $byId = collect($rows)->keyBy('id');
    $expected = [
        ['administrator_event:'.$administrator->id, 'administrators', 'created', 'Recorded Administrator', '/administrators/'.$this->target->id],
        ['status_change:'.$account->id, 'accounts', 'suspended', 'Recorded Account', '/accounts/'.$this->account->id],
        ['status_change:'.$store->id, 'stores', 'suspended', 'Recorded Store', '/stores/'.$this->store->id],
        ['plan_version:'.$plan->id, 'plans', 'plan_version_recorded', 'Recorded Plan', '/plans/'.$plan->plan_id],
        ['subscription_activation:'.$subscription->id, 'subscriptions', 'activated', 'Recorded Subscriber', '/accounts/'.$this->account->id.'/subscription'],
    ];
    foreach ($expected as [$id, $module, $action, $target, $path]) {
        expect($byId[$id])->toMatchArray(['module' => $module, 'action' => $action,
            'actor' => ['id' => $this->admin->id, 'name' => 'Recorded Actor', 'name_recorded' => true]])
            ->and($byId[$id]['target'])->toMatchArray(['name' => $target, 'path' => $path]);
    }
    expect($byId['status_change:'.$account->id]['changes'])->toBe(['from_status' => 'active', 'to_status' => 'suspended']);
    expect(array_keys($rows[0]))->toBe(['id', 'source', 'source_id', 'module', 'action', 'occurred_at', 'actor', 'target', 'reason', 'changes', 'details']);
});

it('maps reactivation renewal and legacy plan versions without inventing a plan creation event', function () {
    $this->activityAdministrator(['action' => 'status_changed', 'from_status' => 'suspended', 'to_status' => 'active']);
    $this->activityStatus('store', ['from_status' => 'suspended', 'to_status' => 'active']);
    $this->activityPlan(['version' => 1, 'actor_name_snapshot' => null]);
    $this->activitySubscription(['action' => 'renew']);
    $rows = $this->getJson($this->activityQuery())->assertOk()->assertJsonCount(4, 'data')->json('data');
    expect(collect($rows)->pluck('action')->sort()->values()->all())
        ->toBe(['plan_version_recorded', 'reactivated', 'reactivated', 'renewed']);
});

it('represents system console and missing historical actor names honestly', function () {
    $system = $this->activityPlan(['actor_user_id' => null, 'actor_name_snapshot' => null]);
    $legacy = $this->activityPlan(['actor_name_snapshot' => null]);
    $console = $this->activityAdministrator(['actor_user_id' => null, 'actor_name_snapshot' => 'Console']);
    $rows = collect($this->getJson($this->activityQuery())->assertOk()->json('data'))->keyBy('id');
    expect($rows['plan_version:'.$system->id]['actor'])->toBe(['id' => null, 'name' => 'System', 'name_recorded' => false])
        ->and($rows['plan_version:'.$legacy->id]['actor'])->toBe(['id' => $this->admin->id, 'name' => 'Administrator #'.$this->admin->id, 'name_recorded' => false])
        ->and($rows['administrator_event:'.$console->id]['actor'])->toBe(['id' => null, 'name' => 'Console', 'name_recorded' => true]);
});

it('intersects module action actor date and saved-name search filters', function () {
    $this->activitySources();
    $matched = $this->activityStatus('store', ['from_status' => 'suspended', 'to_status' => 'active',
        'target_name_snapshot' => 'Special Store', 'reason' => 'Support resolved request']);
    $this->activityStatus('store', ['from_status' => 'suspended', 'to_status' => 'active',
        'target_name_snapshot' => 'Special Store', 'created_at' => now()->subDays(2)]);
    $this->activityStatus('store', ['from_status' => 'suspended', 'to_status' => 'active',
        'target_name_snapshot' => 'Special Store', 'actor_user_id' => $this->target->id]);
    $this->getJson($this->activityQuery(['module' => 'stores', 'action' => 'reactivated', 'actor_id' => $this->admin->id,
        'date_from' => '2026-10-18', 'date_to' => '2026-10-18', 'q' => '  special  ']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'status_change:'.$matched->id)
        ->assertJsonPath('meta.filters.q', 'special');
});

it('searches recorded actor target and reason while ignoring current identity and private snapshots', function (string $query, int $count) {
    $this->activityStatus('store', ['target_name_snapshot' => 'Archived Shop', 'actor_name_snapshot' => 'Archived Operator', 'reason' => 'Support reference Mango']);
    $this->getJson($this->activityQuery(['q' => $query]))->assertOk()->assertJsonCount($count, 'data');
})->with([
    ['Archived Shop', 1], ['Archived Operator', 1], ['Mango', 1], ['Current Actor', 0],
    ['hidden', 0], ["%' OR 1=1 --", 0],
]);

it('filters nullable system actors separately from administrators', function () {
    $this->activitySources();
    $this->activityPlan(['actor_user_id' => null, 'actor_name_snapshot' => null]);
    $this->activityAdministrator(['actor_user_id' => null, 'actor_name_snapshot' => 'Console']);
    $this->getJson($this->activityQuery(['actor_id' => 0]))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($this->activityQuery(['actor_id' => $this->admin->id]))->assertOk()->assertJsonCount(5, 'data');
    $this->getJson($this->activityQuery(['actor_id' => 999999]))->assertOk()->assertJsonCount(0, 'data');
});

it('uses inclusive Manila dates and an exclusive UTC boundary for the following date', function () {
    foreach (['2026-10-17 15:59:59', '2026-10-17 16:00:00', '2026-10-18 15:59:59', '2026-10-18 16:00:00'] as $time) {
        $this->activityStatus('account', ['created_at' => $time, 'reason' => $time]);
    }
    $rows = $this->getJson($this->activityQuery(['date_from' => '2026-10-18', 'date_to' => '2026-10-18']))
        ->assertOk()->assertJsonCount(2, 'data')->json('data');
    expect(collect($rows)->pluck('reason')->all())->toBe(['2026-10-18 15:59:59', '2026-10-17 16:00:00']);
});

it('defaults to the last thirty Manila calendar dates', function () {
    $this->activityStatus('account', ['created_at' => '2026-09-18 15:59:59']);
    $this->activityStatus('account', ['created_at' => '2026-09-18 16:00:00']);
    $this->activityStatus('account', ['created_at' => '2026-10-18 15:59:59']);
    $this->activityStatus('account', ['created_at' => '2026-10-18 16:00:00']);
    $this->getJson($this->activityQuery())->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.filters.date_from', '2026-09-19')->assertJsonPath('meta.filters.date_to', '2026-10-18');
});

it('rejects invalid filters and oversized calendar ranges', function (array $query, string $field) {
    $this->getJson($this->activityQuery($query))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['module' => 'sales'], 'module'], [['action' => 'deleted'], 'action'], [['actor_id' => -1], 'actor_id'],
    [['actor_id' => '1.5'], 'actor_id'], [['actor_id' => ['unsafe']], 'actor_id'],
    [['q' => str_repeat('a', 121)], 'q'], [['q' => ['unsafe']], 'q'],
    [['per_page' => 0], 'per_page'], [['per_page' => 101], 'per_page'], [['per_page' => '2.5'], 'per_page'],
    [['date_from' => '2026-10-18'], 'date_to'], [['date_to' => '2026-10-18'], 'date_from'],
    [['date_from' => '2026-02-30', 'date_to' => '2026-03-02'], 'date_from'],
    [['date_from' => '2026-10-19', 'date_to' => '2026-10-18'], 'date_to'],
    [['date_from' => '2025-10-17', 'date_to' => '2026-10-18'], 'date_to'],
]);

it('accepts the maximum inclusive date range and page size', function () {
    $this->activitySources();
    $this->getJson($this->activityQuery(['date_from' => '2025-10-18', 'date_to' => '2026-10-18', 'per_page' => 100]))
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.per_page', 100);
});

it('keeps cursor traversal deterministic across source and identifier ties without duplicates after newer inserts', function () {
    $this->activitySources();
    $this->activityAdministrator(['action' => 'status_changed', 'from_status' => 'active', 'to_status' => 'suspended']);
    $expected = $this->getJson($this->activityQuery(['per_page' => 100]))->assertOk()->json('data');
    expect(count(array_unique(array_column($expected, 'id'))))->toBe(6);
    expect(array_column($expected, 'source'))->toBe(['subscription_activation', 'plan_version', 'status_change', 'status_change', 'administrator_event', 'administrator_event']);
    $first = $this->getJson($this->activityQuery(['per_page' => 1]))->assertOk()->json();
    $this->activityAdministrator(['created_at' => now()->addSecond()]);
    $ids = [$first['data'][0]['id']];
    $cursor = $first['meta']['next_cursor'];
    $second = null;
    for ($page = 0; $cursor !== null && $page < 10; $page++) {
        $response = $this->getJson($this->activityQuery(['per_page' => 1, 'cursor' => $cursor]))->assertOk()->json();
        $second ??= $response;
        $ids[] = $response['data'][0]['id'];
        $cursor = $response['meta']['next_cursor'];
    }
    expect($ids)->toBe(array_column($expected, 'id'))->and($cursor)->toBeNull();
    $this->getJson($this->activityQuery(['per_page' => 1, 'cursor' => $second['meta']['prev_cursor']]))
        ->assertOk()->assertJsonPath('data.0.id', $first['data'][0]['id']);
});

it('rejects malformed cursor payloads before querying source data', function (mixed $cursor) {
    if (is_array($cursor) && isset($cursor['payload'])) {
        $cursor = rtrim(strtr(base64_encode(json_encode($cursor['payload'])), '+/', '-_'), '=');
    }
    $this->getJson($this->activityQuery(['cursor' => $cursor]))->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with([
    ['not-base64-json'], [str_repeat('a', 2049)], [['unsafe']],
    [['payload' => ['id' => 1]]],
    [['payload' => ['created_at' => 'bad-date', 'source_rank' => 1, 'id' => 1, '_pointsToNextItems' => true]]],
    [['payload' => ['created_at' => '2026-10-18 04:00:00', 'source_rank' => 5, 'id' => 1, '_pointsToNextItems' => true]]],
    [['payload' => ['created_at' => '2026-10-18 04:00:00', 'source_rank' => 1, 'id' => 0, '_pointsToNextItems' => true]]],
    [['payload' => ['created_at' => '2026-10-18 04:00:00', 'source_rank' => true, 'id' => 1, '_pointsToNextItems' => true]]],
    [['payload' => ['created_at' => '2026-10-18 04:00:00', 'source_rank' => 1, 'id' => true, '_pointsToNextItems' => true]]],
    [['payload' => ['created_at' => '2026-10-18 04:00:00', 'source_rank' => 1, 'id' => 1.5, '_pointsToNextItems' => true]]],
    [['payload' => ['created_at' => '2026-10-18 04:00:00', 'source_rank' => 1, 'id' => 1, '_pointsToNextItems' => 'false']]],
    [['payload' => ['created_at' => '2026-10-18 04:00:00', 'source_rank' => 1, 'id' => 1, '_pointsToNextItems' => true, 'private' => 'extra']]],
]);

it('exposes only allowlisted audit fields and never raw private snapshots or operation credentials', function () {
    $this->activitySources();
    $response = $this->getJson($this->activityQuery())->assertOk();
    foreach (['request_key', 'request_hash', 'review_hash', 'review_token', 'quote_hash', 'result_snapshot',
        'snapshot', 'payment_reference', 'administrator_email_snapshot', 'password', 'remember_token',
        'hidden-admin-result', 'hidden-password', 'hidden-plan-terms', 'hidden-subscription-terms',
        'hidden-subscription-snapshot', 'hidden-payment', 'hidden-administrator@example.test'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

it('reads audit sources without appending copying or changing audit history', function () {
    $this->activitySources();
    $tables = ['admin_administrator_events', 'admin_status_changes', 'plan_versions', 'subscription_activations'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    $this->getJson($this->activityQuery())->assertOk();
    $queries = $connection->getQueryLog();
    $connection->disableQueryLog();
    foreach ($queries as $query) {
        expect($query['query'])->not->toMatch('/^\s*(insert|update|delete)\b/i');
    }
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
});

it('keeps source hydration query counts bounded as mixed-source result pages grow', function () {
    $this->activitySources();
    $small = $this->readQueries(fn () => $this->getJson($this->activityQuery(['per_page' => 100]))->assertOk()->assertJsonCount(5, 'data'));
    for ($index = 0; $index < 5; $index++) {
        $this->activitySources();
    }
    $large = $this->readQueries(fn () => $this->getJson($this->activityQuery(['per_page' => 100]))->assertOk()->assertJsonCount(30, 'data'));
    expect(count($small))->toBeLessThanOrEqual(10)->and(count($large))->toBe(count($small));
});

it('preserves new plan actor snapshots in both audit views after administrator renames', function () {
    $plan = app(SavePlan::class)->handle(['code' => 'activity-managed', 'name' => 'Managed Plan',
        'monthly_price_centavos' => 4900, 'active_product_limit' => 500, 'quota_scope' => 'store',
        'store_limit' => 1, 'staff_limit' => 0, 'catalog_status' => 'draft',
        'change_note' => 'Approved managed plan'], null, $this->admin->id);
    $this->admin->forceFill(['name' => 'Later Name'])->save();
    $this->getJson('/api/v1/admin/plans/'.$plan->id.'/versions')->assertOk()
        ->assertJsonPath('data.0.actor.name', 'Current Actor');
    $this->getJson($this->activityQuery(['module' => 'plans']))->assertOk()
        ->assertJsonPath('data.0.actor.name', 'Current Actor')->assertJsonPath('data.0.actor.name_recorded', true);
});

it('does not substitute present-day administrator names for missing legacy plan actor snapshots', function () {
    $version = $this->activityPlan(['actor_name_snapshot' => null]);
    $this->getJson('/api/v1/admin/plans/'.$version->plan_id.'/versions')->assertOk()
        ->assertJsonPath('data.0.actor.name', 'Administrator #'.$this->admin->id.' (name not recorded)');
    expect(PlanVersion::findOrFail($version->id)->actor_name_snapshot)->toBeNull();
});
