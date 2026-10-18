<?php

use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;
use Tests\Support\SubscriptionMonitoringFixtures;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class, SubscriptionMonitoringFixtures::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T04:00:00Z'));
    $this->seed(PlanSeeder::class);
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->account = $this->monitoringAccount();
    $this->actingAs($this->admin, 'web');
});

it('restricts subscription monitoring to current active platform administrators', function (string $actor) {
    $this->resetBrowserGuards();
    if ($actor === 'owner') {
        $this->actingAs($this->account->owner, 'web');
    } elseif ($actor === 'suspended administrator') {
        $this->admin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->admin, 'web');
    }

    $this->getJson($this->monitoringQuery())->assertStatus($actor === 'guest' ? 401 : 403);
})->with(['guest', 'owner', 'suspended administrator']);

it('rechecks administrator suspension even when the authenticated user instance is stale', function () {
    $this->getJson($this->monitoringQuery())->assertOk();
    DB::table('users')->where('id', $this->admin->id)->update(['status' => 'suspended', 'admin_status_revision' => 1]);
    $this->getJson($this->monitoringQuery())->assertForbidden();
});

it('returns one current Free fallback per account with explicit defaults and Manila metadata', function () {
    Plan::where('code', 'free')->update(['name' => 'Free essentials']);

    $response = $this->getJson($this->monitoringQuery())->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.account.id', $this->account->id)
        ->assertJsonPath('data.0.owner.id', $this->account->owner_user_id)
        ->assertJsonPath('data.0.state', 'free')->assertJsonPath('data.0.is_paid', false)
        ->assertJsonPath('data.0.is_expiring', false)
        ->assertJsonPath('data.0.current', ['subscription_id' => null, 'plan' => ['code' => 'free', 'name' => 'Free essentials'], 'starts_at' => null, 'ends_at' => null])
        ->assertJsonPath('data.0.coverage_ends_at', null)->assertJsonPath('data.0.next_period', null)
        ->assertJsonPath('data.0.scheduled_periods', 0)->assertJsonPath('data.0.last_paid_ended_at', null)
        ->assertJsonPath('meta.filters', ['q' => '', 'status' => 'all', 'expiry_days' => 7, 'account_status' => 'all'])
        ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 1)->assertJsonPath('meta.time_zone', 'Asia/Manila')
        ->assertJsonPath('summary', ['total' => 1, 'active_basic' => 0, 'expiring' => 0, 'expired_paid' => 0, 'free' => 1, 'other_paid' => 0]);

    expect(CarbonImmutable::parse($response->json('meta.generated_at'))->equalTo(now()))->toBeTrue();
});

it('chooses the highest identifier among effective active periods while ignoring canceled and future periods', function () {
    $this->monitoringPeriod($this->account, 'basic');
    $current = $this->monitoringPeriod($this->account, 'plus', ['ends_at' => now()->addDays(15)]);
    $future = $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(30)]);
    $this->monitoringPeriod($this->account, 'pro', ['status' => 'canceled', 'ends_at' => now()->addDays(40)]);

    $this->getJson($this->monitoringQuery())->assertOk()->assertJsonPath('data.0.current.subscription_id', $current->id)
        ->assertJsonPath('data.0.current.plan.code', 'plus')->assertJsonPath('data.0.state', 'other_paid')
        ->assertJsonPath('data.0.next_period.id', $future->id)->assertJsonPath('data.0.scheduled_periods', 1);
});

it('treats a period end as exclusive and a period start as inclusive at the same instant', function () {
    $expired = $this->monitoringPeriod($this->account, 'basic', ['ends_at' => now()]);
    $other = $this->monitoringAccount();
    $starting = $this->monitoringPeriod($other, 'basic', ['starts_at' => now()]);
    $rows = collect($this->getJson($this->monitoringQuery())->assertOk()->json('data'))->keyBy('account.id');

    expect($rows[$this->account->id]['state'])->toBe('expired_paid')
        ->and($rows[$this->account->id]['current']['subscription_id'])->toBeNull()
        ->and($rows[$this->account->id]['current']['plan']['code'])->toBe('free')
        ->and($rows[$other->id]['current']['subscription_id'])->toBe($starting->id);
    expect(CarbonImmutable::parse($rows[$this->account->id]['last_paid_ended_at'])->equalTo($expired->ends_at))->toBeTrue();
});

it('uses immutable paid names for both current and scheduled periods after catalog edits', function () {
    $current = $this->monitoringPeriod($this->account);
    $plan = Plan::where('code', 'basic')->firstOrFail();
    $old = $plan->versions()->firstOrFail();
    $renewal = PlanVersion::create(['plan_id' => $plan->id, 'version' => 2,
        'terms' => array_replace($old->terms, ['name' => 'Agreed renewal name', 'monthly_price_centavos' => 5900])]);
    $next = $this->monitoringPeriod($this->account, 'basic', ['plan_version_id' => $renewal->id,
        'starts_at' => $current->ends_at, 'ends_at' => now()->addDays(35)]);
    $plan->update(['name' => 'Renamed catalog only', 'catalog_status' => 'retired', 'is_active' => false]);

    $this->getJson($this->monitoringQuery())->assertOk()->assertJsonPath('data.0.current.plan.name', 'Basic')
        ->assertJsonPath('data.0.next_period.id', $next->id)->assertJsonPath('data.0.next_period.plan.name', 'Agreed renewal name');
});

it('extends paid coverage through contiguous and overlapping prepaid periods without shortening for nested periods', function () {
    $this->monitoringPeriod($this->account, 'basic', ['ends_at' => now()->addDays(3)]);
    $nested = $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $this->monitoringPeriod($this->account, 'plus', ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(5)]);
    $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(10)]);
    $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(12)]);
    $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDays(13), 'ends_at' => now()->addDays(15)]);

    $response = $this->getJson($this->monitoringQuery())->assertOk()->assertJsonPath('data.0.state', 'active_basic')
        ->assertJsonPath('data.0.is_expiring', false)->assertJsonPath('data.0.scheduled_periods', 5)
        ->assertJsonPath('data.0.next_period.id', $nested->id);
    expect(CarbonImmutable::parse($response->json('data.0.coverage_ends_at'))->equalTo(now()->addDays(12)))->toBeTrue();
    $this->getJson($this->monitoringQuery(['expiry_days' => 30]))->assertOk()->assertJsonPath('data.0.is_expiring', true);
});

it('stops coverage at a gap and excludes canceled or Free periods as paid bridges', function () {
    $current = $this->monitoringPeriod($this->account, 'basic', ['ends_at' => now()->addDays(2)]);
    $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(8), 'status' => 'canceled']);
    $this->monitoringPeriod($this->account, 'free', ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(8)]);
    $next = $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDays(8), 'ends_at' => now()->addDays(12)]);

    $response = $this->getJson($this->monitoringQuery())->assertOk()->assertJsonPath('data.0.is_expiring', true)
        ->assertJsonPath('data.0.scheduled_periods', 1)->assertJsonPath('data.0.next_period.id', $next->id);
    expect(CarbonImmutable::parse($response->json('data.0.coverage_ends_at'))->equalTo($current->ends_at))->toBeTrue();
});

it('keeps future-only paid periods separate from current Free and orders the next period by start then identifier', function () {
    $first = $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(5)]);
    $this->monitoringPeriod($this->account, 'plus', ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(6)]);
    $this->monitoringPeriod($this->account, 'pro', ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4), 'status' => 'canceled']);

    $this->getJson($this->monitoringQuery())->assertOk()->assertJsonPath('data.0.state', 'free')
        ->assertJsonPath('data.0.current.subscription_id', null)->assertJsonPath('data.0.is_paid', false)
        ->assertJsonPath('data.0.is_expiring', false)->assertJsonPath('data.0.coverage_ends_at', null)
        ->assertJsonPath('data.0.next_period.id', $first->id)->assertJsonPath('data.0.scheduled_periods', 2);
});

it('never marks an open-ended current or contiguous scheduled paid period as expiring', function (bool $scheduledOpenEnd) {
    $current = $this->monitoringPeriod($this->account, 'basic', ['ends_at' => $scheduledOpenEnd ? now()->addDay() : null]);
    if ($scheduledOpenEnd) {
        $this->monitoringPeriod($this->account, 'basic', ['starts_at' => $current->ends_at, 'ends_at' => null]);
    }

    $this->getJson($this->monitoringQuery(['expiry_days' => 30]))->assertOk()->assertJsonPath('data.0.is_paid', true)
        ->assertJsonPath('data.0.is_expiring', false)->assertJsonPath('data.0.coverage_ends_at', null)
        ->assertJsonPath('summary.expiring', 0);
})->with([false, true]);

it('uses an inclusive exact cutoff measured from the current instant for the selected expiry window', function (int $days, int $seconds, bool $expected) {
    $this->monitoringPeriod($this->account, 'basic', ['ends_at' => now()->addDays($days)->addSeconds($seconds)]);

    $this->getJson($this->monitoringQuery(['expiry_days' => $days]))->assertOk()->assertJsonPath('data.0.is_expiring', $expected)
        ->assertJsonPath('summary.expiring', (int) $expected)->assertJsonPath('meta.filters.expiry_days', $days);
})->with([[7, -1, true], [7, 0, true], [7, 1, false], [30, -1, true], [30, 0, true], [30, 1, false]]);

it('distinguishes lapsed paid history from never-paid Free and keeps a future renewal visible', function () {
    $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->subMonth(), 'ends_at' => now()->subDays(2)]);
    $last = $this->monitoringPeriod($this->account, 'plus', ['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay(), 'status' => 'canceled']);
    $next = $this->monitoringPeriod($this->account, 'basic', ['starts_at' => now()->addDay(), 'ends_at' => now()->addMonth()]);
    $free = $this->monitoringAccount();
    $this->monitoringPeriod($free, 'free');
    $rows = collect($this->getJson($this->monitoringQuery())->assertOk()->assertJsonPath('summary.expired_paid', 1)
        ->assertJsonPath('summary.free', 1)->json('data'))->keyBy('account.id');

    expect($rows[$this->account->id]['state'])->toBe('expired_paid')
        ->and($rows[$this->account->id]['is_paid'])->toBeFalse()
        ->and($rows[$this->account->id]['next_period']['id'])->toBe($next->id)
        ->and($rows[$free->id]['state'])->toBe('free')
        ->and($rows[$free->id]['last_paid_ended_at'])->toBeNull();
    expect(CarbonImmutable::parse($rows[$this->account->id]['last_paid_ended_at'])->equalTo($last->ends_at))->toBeTrue();
});

it('classifies a zero-price legacy non-Free plan as paid rather than hiding it in Free', function () {
    Plan::create(['code' => 'legacy-comp', 'name' => 'Legacy complimentary', 'monthly_price_centavos' => 0]);
    $this->monitoringPeriod($this->account, 'legacy-comp', ['ends_at' => now()->addDays(2)]);

    $this->getJson($this->monitoringQuery(['status' => 'other_paid']))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.state', 'other_paid')->assertJsonPath('data.0.is_paid', true)
        ->assertJsonPath('data.0.is_expiring', true)->assertJsonPath('summary.other_paid', 1);
});

it('keeps account and owner suspension separate from paid coverage and filters only account status', function () {
    $this->account->update(['status' => 'suspended']);
    $this->account->owner->forceFill(['status' => 'suspended'])->save();
    $this->monitoringPeriod($this->account);

    $this->getJson($this->monitoringQuery(['account_status' => 'suspended']))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.account.status', 'suspended')->assertJsonPath('data.0.owner.status', 'suspended')
        ->assertJsonPath('data.0.state', 'active_basic')->assertJsonPath('data.0.is_paid', true)
        ->assertJsonPath('data.0.is_expiring', true)->assertJsonPath('summary.active_basic', 1);
    $this->getJson($this->monitoringQuery(['account_status' => 'active']))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.total', 0);
});

it('applies status filters without excluding expiring Basic from Active Basic', function (string $status, int $expected) {
    $this->monitoringPeriod($this->account, 'basic');
    $this->monitoringPeriod($this->monitoringAccount(), 'basic', ['ends_at' => now()->addDays(20)]);
    $this->monitoringPeriod($this->monitoringAccount(), 'basic', ['ends_at' => now()]);
    $this->monitoringAccount();
    $this->monitoringPeriod($this->monitoringAccount(), 'plus');

    $this->getJson($this->monitoringQuery(['status' => $status]))->assertOk()->assertJsonCount($expected, 'data')
        ->assertJsonPath('meta.total', $expected)->assertJsonPath('summary', [
            'total' => 5, 'active_basic' => 2, 'expiring' => 2, 'expired_paid' => 1, 'free' => 1, 'other_paid' => 1,
        ]);
})->with([['all', 5], ['active_basic', 2], ['expiring', 2], ['expired_paid', 1], ['free', 1], ['other_paid', 1]]);

it('scopes summaries to search account status and expiry window while ignoring selected status and page', function () {
    $this->account->update(['name' => 'Family Current']);
    $this->monitoringPeriod($this->account, 'basic', ['ends_at' => now()->addDays(12)]);
    $expired = $this->monitoringAccount(['name' => 'Family Lapsed']);
    $this->monitoringPeriod($expired, 'basic', ['ends_at' => now()]);
    $this->monitoringAccount(['name' => 'Family Suspended', 'status' => 'suspended']);
    $this->monitoringPeriod($this->monitoringAccount(['name' => 'Unrelated account']), 'plus');

    foreach (['free', 'active_basic'] as $status) {
        $this->getJson($this->monitoringQuery(['q' => '  Family  ', 'account_status' => 'active',
            'status' => $status, 'expiry_days' => 30, 'per_page' => 1, 'page' => 2]))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.filters.q', 'Family')
            ->assertJsonPath('summary', ['total' => 2, 'active_basic' => 1, 'expiring' => 1, 'expired_paid' => 1, 'free' => 0, 'other_paid' => 0]);
    }
});

it('searches account and current owner identity without searching private provider references', function (string $query, int $expected) {
    $this->account->update(['name' => 'Mango Corner']);
    $this->account->owner->update(['name' => 'Aling Lorna', 'email' => 'lorna-monitor@example.test']);
    $this->monitoringPeriod($this->account);

    $this->getJson($this->monitoringQuery(['q' => $query]))->assertOk()->assertJsonCount($expected, 'data')
        ->assertJsonPath('summary.total', $expected);
})->with([['Mango Corner', 1], ['Aling Lorna', 1], ['lorna-monitor@example.test', 1],
    ['private-provider-reference', 0], ["%' OR 1=1 --", 0]]);

it('paginates accounts in descending identifier order with consistent totals and no duplicate rows', function () {
    $ids = [$this->account->id];
    for ($index = 0; $index < 4; $index++) {
        $ids[] = $this->monitoringAccount(['name' => 'Account '.$index])->id;
    }
    $seen = [];
    foreach ([1 => 2, 2 => 2, 3 => 1] as $page => $count) {
        $response = $this->getJson($this->monitoringQuery(['per_page' => 2, 'page' => $page]))->assertOk()
            ->assertJsonCount($count, 'data')->assertJsonPath('meta.current_page', $page)
            ->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 5)->assertJsonPath('summary.total', 5);
        array_push($seen, ...array_column(array_column($response->json('data'), 'account'), 'id'));
    }
    expect($seen)->toBe(array_reverse($ids))->and(count(array_unique($seen)))->toBe(5);
    $this->getJson($this->monitoringQuery(['per_page' => 100, 'page' => 1000000]))->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null)
        ->assertJsonPath('summary.total', 5);
});

it('rejects unsupported or malformed monitoring filters', function (array $query, string $field) {
    $this->getJson($this->monitoringQuery($query))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['q' => str_repeat('a', 121)], 'q'], [['q' => ['unsafe']], 'q'],
    [['status' => 'active'], 'status'], [['status' => ['free']], 'status'],
    [['account_status' => 'deleted'], 'account_status'], [['account_status' => ['active']], 'account_status'],
    [['expiry_days' => 0], 'expiry_days'], [['expiry_days' => 14], 'expiry_days'], [['expiry_days' => '7.5'], 'expiry_days'], [['expiry_days' => ['7']], 'expiry_days'],
    [['page' => 0], 'page'], [['page' => 1000001], 'page'], [['page' => '2.5'], 'page'], [['page' => ['1']], 'page'],
    [['per_page' => 0], 'per_page'], [['per_page' => 101], 'per_page'], [['per_page' => '2.5'], 'per_page'], [['per_page' => ['1']], 'per_page'],
]);

it('exposes only monitoring fields and leaves financial audit and subscription records unchanged', function () {
    $this->monitoringPeriod($this->account);
    $tables = ['accounts', 'users', 'plans', 'plan_versions', 'subscriptions', 'subscription_activations', 'admin_status_changes', 'admin_administrator_events'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $response = $this->getJson($this->monitoringQuery())->assertOk();
    $row = $response->json('data.0');

    expect(array_keys($row))->toBe(['account', 'owner', 'state', 'is_expiring', 'is_paid', 'current',
        'coverage_ends_at', 'next_period', 'scheduled_periods', 'last_paid_ended_at'])
        ->and(array_keys($row['account']))->toBe(['id', 'name', 'status'])
        ->and(array_keys($row['owner']))->toBe(['id', 'name', 'email', 'status'])
        ->and(array_keys($row['current']))->toBe(['subscription_id', 'plan', 'starts_at', 'ends_at'])
        ->and(array_keys($row['current']['plan']))->toBe(['code', 'name']);
    foreach (['private-provider-reference', 'provider_reference', 'payment_reference', 'request_key', 'password',
        'remember_token', 'entitlements_json', 'monthly_price_centavos', 'quote_hash'] as $private) {
        expect($response->getContent())->not->toContain($private);
    }
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
});

it('keeps database reads constant as the page includes more accounts owners and prepaid periods', function () {
    $this->monitoringPeriod($this->account);
    for ($index = 0; $index < 11; $index++) {
        $account = $this->monitoringAccount();
        $this->monitoringPeriod($account);
        $this->monitoringPeriod($account, 'basic', ['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(35)]);
    }

    $this->assertReadQueriesStayConstant(
        fn () => $this->getJson($this->monitoringQuery(['per_page' => 1]))->assertOk()->assertJsonCount(1, 'data'),
        fn () => $this->getJson($this->monitoringQuery(['per_page' => 12]))->assertOk()->assertJsonCount(12, 'data'),
    );
});
