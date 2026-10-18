<?php

namespace Tests\Concurrency;

use App\Models\Account;
use App\Models\Store;
use App\Models\User;
use App\Services\Auth\RegisterStoreOwner;
use Database\Seeders\PlanSeeder;
use Tests\Support\AdminActivityFixtures;
use Tests\Support\CountsDatabaseQueries;
use Tests\Support\MySqlConcurrencyDatabase;
use Tests\TestCase;

class MySqlActivityLogTest extends TestCase
{
    use AdminActivityFixtures, CountsDatabaseQueries;

    protected User $admin;

    protected User $target;

    protected Store $store;

    protected Account $account;

    public function test_activity_union_json_search_and_cursor_work_on_the_real_database(): void
    {
        MySqlConcurrencyDatabase::configure($this->app);
        MySqlConcurrencyDatabase::reset();
        $this->seed(PlanSeeder::class);
        $this->travelTo(now('UTC')->setDate(2026, 10, 18)->setTime(4, 0));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());

        $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
        $this->target = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
        $owner = app(RegisterStoreOwner::class)->handle([
            'name' => 'Activity compatibility owner', 'email' => 'activity-mysql-owner@example.test',
            'store_name' => 'Activity compatibility store', 'password' => 'test-only-password',
        ]);
        $this->account = Account::where('owner_user_id', $owner->id)->sole();
        $this->store = Store::where('account_id', $this->account->id)->sole();
        $this->withHeader('Origin', 'http://localhost:3001')->withCredentials()->actingAs($this->admin, 'web');
        $events = $this->activitySources();
        $filters = ['actor_id' => $this->admin->id, 'date_from' => '2026-10-18', 'date_to' => '2026-10-18'];

        $first = $this->getJson($this->activityQuery($filters + ['per_page' => 2]))->assertOk()
            ->assertJsonCount(2, 'data')->json();
        $this->assertNotNull($first['meta']['next_cursor']);
        $this->assertNull($first['meta']['prev_cursor']);
        $second = $this->getJson($this->activityQuery($filters + ['per_page' => 2, 'cursor' => $first['meta']['next_cursor']]))
            ->assertOk()->assertJsonCount(2, 'data')->json();
        $third = $this->getJson($this->activityQuery($filters + ['per_page' => 2, 'cursor' => $second['meta']['next_cursor']]))
            ->assertOk()->assertJsonCount(1, 'data')->json();
        $this->assertNull($third['meta']['next_cursor']);
        $ids = [...array_column($first['data'], 'id'), ...array_column($second['data'], 'id'), ...array_column($third['data'], 'id')];
        $this->assertCount(5, array_unique($ids));
        $this->getJson($this->activityQuery($filters + ['per_page' => 2, 'cursor' => $second['meta']['prev_cursor']]))
            ->assertOk()->assertJsonPath('data.0.id', $first['data'][0]['id']);

        $plan = $this->activityPlan(['terms' => ['name' => 'Only JSON plan target'], 'change_note' => 'Other note']);
        $subscription = $this->activitySubscription(['snapshot' => ['account_name' => 'Only JSON account target'], 'reason' => 'Other note']);
        $this->getJson($this->activityQuery($filters + ['q' => 'Only JSON plan target']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'plan_version:'.$plan->id);
        $this->getJson($this->activityQuery($filters + ['q' => 'Only JSON account target']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'subscription_activation:'.$subscription->id);

        $before = array_map(fn ($event) => $event->fresh()->getAttributes(), $events);
        $queries = $this->readQueries(fn () => $this->getJson($this->activityQuery($filters + ['per_page' => 100]))->assertOk()
            ->assertJsonCount(7, 'data'));
        $this->assertLessThanOrEqual(10, count($queries));
        $this->assertSame($before, array_map(fn ($event) => $event->fresh()->getAttributes(), $events));
    }
}
