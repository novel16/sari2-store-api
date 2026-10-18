<?php

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\Profile\UpdateAdministratorProfile;
use App\Services\Admin\SavePlan;
use App\Services\Admin\Status\StatusChangeService;
use App\Services\Admin\Subscriptions\ActivateSubscription;
use App\Services\Admin\Subscriptions\SubscriptionQuote;
use App\Services\Auth\RegisterStoreOwner;
use App\Services\Settings\UpdateAccount;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active',
        'password' => Hash::make('admin-password-123')]);
    $this->actingAs($this->admin, 'web');
});

it('updates administrator profile without attaching an owner workspace', function () {
    $this->patchJson('/api/v1/admin/profile', ['name' => 'Updated Administrator', 'email' => $this->admin->email])
        ->assertOk()->assertJsonPath('data.name', 'Updated Administrator')->assertJsonPath('data.platform_role', 'platform_admin')
        ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
    expect($this->admin->fresh()->name)->toBe('Updated Administrator');
    foreach (['accounts', 'stores', 'account_memberships'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('requires current password for administrator email changes and invalidates old credentials', function () {
    $old = $this->admin->email;
    $remember = $this->admin->remember_token;
    Password::broker()->createToken($this->admin);
    $this->admin->createToken('old administrator token');
    DB::table('sessions')->insert(['id' => 'admin-old-device', 'user_id' => $this->admin->id,
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    $input = ['name' => $this->admin->name, 'email' => 'new-admin@example.test'];
    $this->patchJson('/api/v1/admin/profile', $input)->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->patchJson('/api/v1/admin/profile', [...$input, 'current_password' => 'wrong-password'])->assertUnprocessable();
    $this->patchJson('/api/v1/admin/profile', [...$input, 'current_password' => 'admin-password-123'])
        ->assertOk()->assertJsonPath('data.email', $input['email']);
    expect($this->admin->fresh()->remember_token)->not->toBe($remember);
    expect($this->admin->fresh()->email_verified_at)->toBeNull();
    expect($this->admin->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('sessions', ['id' => 'admin-old-device']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $old]);
    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('changes only the current administrator password and revokes their other sessions', function () {
    $other = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    foreach ([['id' => 'admin-other-session', 'user_id' => $this->admin->id], ['id' => 'unrelated-session', 'user_id' => $other->id]] as $row) {
        DB::table('sessions')->insert($row + ['payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    }
    $this->admin->createToken('old');
    Password::broker()->createToken($this->admin);
    $input = ['current_password' => 'admin-password-123', 'password' => 'changed-admin-password-123', 'password_confirmation' => 'changed-admin-password-123'];
    $this->patchJson('/api/v1/admin/password', [...$input, 'current_password' => 'wrong-password'])->assertUnprocessable();
    $this->patchJson('/api/v1/admin/password', $input)->assertOk();
    expect(Hash::check($input['password'], $this->admin->fresh()->password))->toBeTrue();
    $this->assertDatabaseMissing('sessions', ['id' => 'admin-other-session']);
    $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
    expect($this->admin->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->admin->email]);
    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('rejects unsafe administrator password changes', function (array $input) {
    $this->patchJson('/api/v1/admin/password', array_replace([
        'current_password' => 'admin-password-123', 'password' => 'changed-admin-password-123', 'password_confirmation' => 'changed-admin-password-123',
    ], $input))->assertUnprocessable();
})->with([
    [['password' => 'short', 'password_confirmation' => 'short']],
    [['password_confirmation' => 'different']], [['password' => str_repeat('a', 73)]],
    [['password' => "abc\0password"]], [['password' => str_repeat('é', 37)]],
    [['password' => 'admin-password-123', 'password_confirmation' => 'admin-password-123']],
    [['user_id' => 999]],
]);

it('rejects profile privilege injection', function (string $field) {
    $input = ['name' => $this->admin->name, 'email' => $this->admin->email];
    $this->patchJson('/api/v1/admin/profile', [...$input, $field => 'injected'])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['platform_role', 'status', 'user_id', 'account_id', 'store_id', 'admin_status_revision', 'email_verified_at']);

it('rejects duplicate administrator sign in email', function () {
    $other = User::factory()->create();
    $this->patchJson('/api/v1/admin/profile', ['name' => $this->admin->name, 'email' => $other->email, 'current_password' => 'admin-password-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('protects administrator settings aliases from guests owners and suspended administrators', function (string $actor) {
    $this->resetBrowserGuards();
    if ($actor === 'owner') {
        $this->actingAs(app(RegisterStoreOwner::class)->handle($this->ownerRegistration()), 'web');
    } elseif ($actor === 'suspended administrator') {
        $this->admin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->admin, 'web');
    }
    foreach (['profile', 'password'] as $route) {
        $this->patchJson('/api/v1/admin/'.$route, [])->assertStatus($actor === 'guest' ? 401 : 403);
    }
})->with(['guest', 'owner', 'suspended administrator']);

it('rejects an already admitted stale administrator before any platform write', function (string $operation, bool $restored) {
    $this->seed(PlanSeeder::class);
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $account = Account::where('owner_user_id', $owner->id)->sole();
    $plan = Plan::where('code', 'basic')->sole();
    $quote = app(SubscriptionQuote::class)->create($account, $this->admin, [
        'plan_id' => $plan->id, 'months' => 1, 'settlement_method' => 'manual_payment',
        'amount_centavos' => 4900, 'payment_reference' => 'STALE-ADMIN', 'reason' => 'Verified support payment',
    ]);
    $status = app(StatusChangeService::class)->preview($account, $this->admin, ['status' => 'suspended', 'reason' => 'Support access review']);
    // Keep the request's original active User object while another request commits suspension.
    User::whereKey($this->admin->id)->update(['status' => $restored ? 'active' : 'suspended', 'admin_status_revision' => $restored ? 2 : 1]);
    expect($this->admin->status)->toBe('active');
    $beforeName = $this->admin->name;
    $beforeHash = $this->admin->password;
    try {
        match ($operation) {
            'plan' => app(SavePlan::class)->handle(['name' => 'Forbidden plan edit', 'expected_version' => $plan->version], $plan, $this->admin->id, $this->admin),
            'subscription' => app(ActivateSubscription::class)->handle($account, $this->admin, ['request_key' => (string) Str::uuid(), 'quote' => $quote['quote']]),
            'status' => app(StatusChangeService::class)->change($account, $this->admin, ['request_key' => (string) Str::uuid(), 'review_token' => $status['review_token']]),
            'admin profile' => app(UpdateAdministratorProfile::class)->profile($this->admin, ['name' => 'Forbidden name', 'email' => $this->admin->email], null),
            'admin password' => app(UpdateAdministratorProfile::class)->password($this->admin, ['current_password' => 'admin-password-123', 'password' => 'forbidden-password'], null),
            'shared profile' => app(UpdateAccount::class)->profile($this->admin, ['name' => 'Forbidden name', 'email' => $this->admin->email], null),
            'shared password' => app(UpdateAccount::class)->password($this->admin, ['current_password' => 'admin-password-123', 'password' => 'forbidden-password'], null),
        };
        $this->fail('A stale User object must not authorize a suspended administrator.');
    } catch (HttpExceptionInterface $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    expect($plan->fresh()->name)->toBe($plan->name);
    expect($plan->fresh()->version)->toBe($plan->version);
    expect($account->fresh()->status)->toBe('active');
    expect($this->admin->fresh()->name)->toBe($beforeName);
    expect($this->admin->fresh()->password)->toBe($beforeHash);
    $this->assertDatabaseCount('subscription_activations', 0);
    $this->assertDatabaseCount('admin_status_changes', 0);
})->with(['plan', 'subscription', 'status', 'admin profile', 'admin password', 'shared profile', 'shared password'])->with([false, true]);
