<?php

namespace Tests\Concurrency;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\CountsDatabaseQueries;
use Tests\Support\MySqlConcurrencyDatabase;
use Tests\Support\SubscriptionMonitoringFixtures;
use Tests\TestCase;

class MySqlSubscriptionMonitoringTest extends TestCase
{
    use CountsDatabaseQueries, SubscriptionMonitoringFixtures;

    public function test_monitoring_windows_filters_and_snapshot_reads_work_on_the_real_database(): void
    {
        MySqlConcurrencyDatabase::configure($this->app);
        MySqlConcurrencyDatabase::reset();
        $this->seed(PlanSeeder::class);
        $this->travelTo(now('UTC')->setDate(2026, 10, 18)->setTime(4, 0));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
        $this->withHeader('Origin', 'http://localhost:3001')->withCredentials()->actingAs($admin, 'web');

        $covered = $this->monitoringAccount(['name' => 'Continuous coverage']);
        $current = $this->monitoringPeriod($covered, 'basic', ['ends_at' => now()->addDays(2)]);
        $this->monitoringPeriod($covered, 'basic', ['starts_at' => now()->addDay(), 'ends_at' => now()->addHours(36)]);
        $this->monitoringPeriod($covered, 'basic', ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(20)]);
        $this->monitoringPeriod($covered, 'basic', ['starts_at' => now()->addDays(21), 'ends_at' => now()->addDays(40)]);

        $expired = $this->monitoringAccount(['name' => 'Expired with future restart']);
        $this->monitoringPeriod($expired, 'free');
        $this->monitoringPeriod($expired, 'basic', ['ends_at' => now()]);
        $restart = $this->monitoringPeriod($expired, 'basic', ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(10)]);
        Plan::where('code', 'basic')->update(['name' => 'Changed live catalog']);
        $before = Subscription::orderBy('id')->get()->toJson();

        $queries = $this->readQueries(fn () => $this->getJson($this->monitoringQuery(['q' => 'Continuous', 'expiry_days' => 7]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.expiring', 0)->assertJsonPath('data.0.current.subscription_id', $current->id)
            ->assertJsonPath('data.0.current.plan.name', 'Basic')
            ->assertJsonPath('data.0.coverage_ends_at', now()->addDays(20)->toIso8601String())
            ->assertJsonPath('data.0.scheduled_periods', 3));
        $this->assertLessThanOrEqual(10, count($queries));
        $this->getJson($this->monitoringQuery(['status' => 'expiring', 'expiry_days' => 30]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.account.id', $covered->id)
            ->assertJsonPath('summary.total', 2)->assertJsonPath('summary.expired_paid', 1);
        $this->getJson($this->monitoringQuery(['status' => 'expired_paid']))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account.id', $expired->id)->assertJsonPath('data.0.current.plan.code', 'free')
            ->assertJsonPath('data.0.next_period.id', $restart->id)->assertJsonPath('data.0.coverage_ends_at', null);
        $this->assertSame($before, Subscription::orderBy('id')->get()->toJson());

        $directory = collect($queries)->first(fn ($query) => str_contains($query['query'], 'order by `account_id` desc'));
        $this->assertNotNull($directory);
        $explain = DB::select('EXPLAIN '.$directory['query'], $directory['bindings']);
        $this->assertNotEmpty($explain);
        $eligibleIndexes = collect($explain)->pluck('possible_keys')->filter()->implode(',');
        $this->assertStringContainsString('subscriptions_monitor_account_start', $eligibleIndexes);
    }
}
