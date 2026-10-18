<?php

namespace Tests\Support;

use App\Models\Admin\AdministratorEvent;
use App\Models\Admin\StatusChange;
use App\Models\Admin\SubscriptionActivation;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Subscription;
use Illuminate\Support\Str;

trait AdminActivityFixtures
{
    protected function activityAdministrator(array $overrides = []): AdministratorEvent
    {
        return AdministratorEvent::create(array_replace([
            'administrator_user_id' => $this->target->id,
            'administrator_name_snapshot' => 'Recorded Administrator',
            'administrator_email_snapshot' => 'hidden-administrator@example.test',
            'action' => 'created', 'from_status' => null, 'to_status' => 'active',
            'reason' => 'Recorded administrator creation reason',
            'actor_user_id' => $this->admin->id, 'actor_name_snapshot' => 'Recorded Actor',
            'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'review_hash' => hash('sha256', Str::uuid()),
            'result_snapshot' => ['private_field' => 'hidden-admin-result', 'password' => 'hidden-password'],
            'created_at' => now(),
        ], $overrides));
    }

    protected function activityStatus(string $type = 'account', array $overrides = []): StatusChange
    {
        return StatusChange::create(array_replace([
            'account_id' => $this->account->id, 'store_id' => $type === 'store' ? $this->store->id : null,
            'target_type' => $type, 'target_name_snapshot' => $type === 'store' ? 'Recorded Store' : 'Recorded Account',
            'from_status' => 'active', 'to_status' => 'suspended', 'reason' => 'Recorded availability reason',
            'actor_user_id' => $this->admin->id, 'actor_name_snapshot' => 'Recorded Actor',
            'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64),
            'review_hash' => hash('sha256', Str::uuid()), 'created_at' => now(),
        ], $overrides));
    }

    protected function activityPlan(array $overrides = []): PlanVersion
    {
        $plan = Plan::where('code', 'free')->firstOrFail();

        return PlanVersion::forceCreate(array_replace([
            'plan_id' => $plan->id, 'version' => (int) $plan->versions()->max('version') + 1,
            'terms' => ['code' => 'free', 'name' => 'Recorded Plan', 'monthly_price_centavos' => 4900,
                'private_field' => 'hidden-plan-terms'],
            'actor_user_id' => $this->admin->id, 'actor_name_snapshot' => 'Recorded Actor',
            'change_note' => 'Recorded plan version reason', 'created_at' => now(),
        ], $overrides));
    }

    protected function activitySubscription(array $overrides = []): SubscriptionActivation
    {
        $subscription = Subscription::create(['account_id' => $this->account->id,
            'plan_id' => Plan::where('code', 'free')->firstOrFail()->id, 'status' => 'active',
            'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);

        return SubscriptionActivation::forceCreate(array_replace([
            'account_id' => $this->account->id, 'subscription_id' => $subscription->id,
            'actor_user_id' => $this->admin->id, 'actor_name_snapshot' => 'Recorded Actor',
            'action' => 'activate', 'settlement_method' => 'cash', 'months' => 1,
            'amount_centavos' => 4900, 'payment_reference' => 'hidden-payment-'.Str::uuid(),
            'reason' => 'Recorded subscription reason',
            'snapshot' => ['account_name' => 'Recorded Subscriber', 'terms' => ['name' => 'Recorded Basic',
                'private_field' => 'hidden-subscription-terms'], 'private_field' => 'hidden-subscription-snapshot'],
            'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64),
            'quote_hash' => hash('sha256', Str::uuid()), 'created_at' => now(),
        ], $overrides));
    }

    protected function activitySources(): array
    {
        return [$this->activityAdministrator(), $this->activityStatus(), $this->activityStatus('store'),
            $this->activityPlan(), $this->activitySubscription()];
    }

    protected function activityQuery(array $query = []): string
    {
        return '/api/v1/admin/activity?'.http_build_query($query);
    }
}
