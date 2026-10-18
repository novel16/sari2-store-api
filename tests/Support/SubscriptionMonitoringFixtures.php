<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

trait SubscriptionMonitoringFixtures
{
    protected function monitoringAccount(array $attributes = [], array $ownerAttributes = []): Account
    {
        $owner = User::factory()->create(array_replace([
            'name' => 'Store Owner',
            'platform_role' => 'user',
            'status' => 'active',
        ], $ownerAttributes));

        return Account::create(array_replace([
            'name' => 'Neighborhood Store',
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ], $attributes));
    }

    protected function monitoringPeriod(Account $account, string $code = 'basic', array $attributes = []): Subscription
    {
        $plan = Plan::where('code', $code)->firstOrFail();

        return $account->subscriptions()->create(array_replace([
            'plan_id' => $plan->id,
            'plan_version_id' => $plan->versions()->orderByDesc('version')->value('id'),
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => $code === 'free' ? null : now()->addDays(5),
            'provider' => 'manual',
            'provider_reference' => 'private-provider-reference',
        ], $attributes));
    }

    protected function monitoringQuery(array $query = []): string
    {
        return '/api/v1/admin/subscriptions'.($query ? '?'.http_build_query($query) : '');
    }
}
