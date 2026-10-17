<?php

namespace Tests\Support;

use App\Models\AccountMembership;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;

trait AuthTestHelpers
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        // Refuse to run RefreshDatabase against the application's local database.
        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || ! empty(config('database.connections.sqlite.url'))) {
            throw new RuntimeException('Auth tests require an isolated SQLite :memory: database.');
        }
    }

    protected function ownerRegistration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Maria Reyes',
            'store_name' => 'Aling Maria Store',
            'email' => 'owner-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ], $overrides);
    }

    protected function createOwner(array $overrides = []): User
    {
        $payload = $this->ownerRegistration($overrides);
        $this->postJson('/register', $payload)->assertCreated();
        $user = User::where('email', $payload['email'])->firstOrFail();
        $this->postJson('/logout')->assertOk();

        return $user;
    }

    protected function resetBrowserGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
    }

    protected function ownerStore(User $user): Store
    {
        $accountId = AccountMembership::where('user_id', $user->id)->value('account_id');

        return Store::where('account_id', $accountId)->firstOrFail();
    }
}
