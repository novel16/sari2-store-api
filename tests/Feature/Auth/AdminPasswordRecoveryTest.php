<?php

use App\Models\User;
use App\Services\Auth\RegisterStoreOwner;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->admin = User::factory()->create([
        'platform_role' => 'platform_admin',
        'status' => 'active',
        'admin_status_revision' => 4,
        'password' => Hash::make('admin-password-before-reset'),
    ]);
});

it('keeps admin owner unknown and broker-throttled recovery responses indistinguishable', function () {
    Notification::fake();
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $suspended = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'suspended']);
    $expected = ['message' => 'If an account exists for that email, a password reset link will be sent.'];

    foreach ([$this->admin->email, $owner->email, $suspended->email, 'unknown@example.test', $this->admin->email] as $email) {
        $this->postJson('/forgot-password', ['email' => $email])->assertOk()->assertExactJson($expected);
    }

    Notification::assertSentToTimes($this->admin, ResetPassword::class, 1);
    Notification::assertSentTo($owner, ResetPassword::class);
    Notification::assertSentTo($suspended, ResetPassword::class);
    Notification::assertCount(3);
    $this->assertGuest('web');
});

it('chooses the reset destination from the saved user role regardless of origin or supplied redirect', function (bool $administrator, string $origin) {
    Notification::fake();
    config(['app.admin_url' => 'http://localhost:3001', 'app.frontend_url' => 'http://localhost:3000']);
    $user = $administrator ? $this->admin : app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $expectedBase = $administrator ? 'http://localhost:3001' : 'http://localhost:3000';

    $this->withHeader('Origin', $origin)->postJson('/forgot-password', [
        'email' => $user->email,
        'return_to' => 'https://untrusted.example/reset-password',
        'frontend_url' => 'https://untrusted.example',
        'admin_url' => 'https://untrusted.example',
        'platform_role' => $administrator ? 'user' : 'platform_admin',
    ])->assertOk()->assertExactJson([
        'message' => 'If an account exists for that email, a password reset link will be sent.',
    ]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $expectedBase) {
        $url = $notification->toMail($user)->actionUrl;
        expect($url)->toStartWith($expectedBase.'/reset-password?');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect($query)->toBe(['token' => $notification->token, 'email' => $user->email]);
        expect(parse_url($url, PHP_URL_FRAGMENT))->toBeNull();

        return true;
    });
})->with([
    'administrator from owner app' => [true, 'http://localhost:3000'],
    'owner from administrator app' => [false, 'http://localhost:3001'],
    'administrator with an untrusted origin' => [true, 'https://untrusted.example'],
    'owner with an untrusted origin' => [false, 'https://untrusted.example'],
]);

it('uses trusted deployment URLs and safely encodes reset token and email query values', function (bool $administrator) {
    config([
        'app.admin_url' => 'https://staff.example.test/console/',
        'app.frontend_url' => 'https://owners.example.test/store/',
    ]);
    $user = $administrator ? $this->admin : User::factory()->create();
    $user->forceFill(['email' => 'levon+recovery@example.test'])->save();
    $notification = new ResetPassword('opaque+/token?&email=attacker#fragment');
    $url = $notification->toMail($user)->actionUrl;
    $expectedBase = $administrator ? 'https://staff.example.test/console' : 'https://owners.example.test/store';

    expect($url)->toStartWith($expectedBase.'/reset-password?');
    expect(parse_url($url, PHP_URL_FRAGMENT))->toBeNull();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toBe(['token' => $notification->token, 'email' => $user->email]);
})->with(['administrator' => [true], 'owner' => [false]]);

it('stores an admin recovery token as a hash without including it in the public response', function () {
    Notification::fake();
    $this->postJson('/forgot-password', ['email' => $this->admin->email])->assertOk()->assertExactJson([
        'message' => 'If an account exists for that email, a password reset link will be sent.',
    ]);

    Notification::assertSentTo($this->admin, ResetPassword::class, function (ResetPassword $notification) {
        $stored = DB::table('password_reset_tokens')->where('email', $this->admin->email)->value('token');
        expect($stored)->not->toBe($notification->token);
        expect(Hash::check($notification->token, $stored))->toBeTrue();

        return true;
    });
    expect($this->admin->fresh()->admin_status_revision)->toBe(4);
});

it('resets only the target administrator once while retaining their role status and independence', function (string $status) {
    $this->admin->forceFill(['status' => $status, 'remember_token' => 'remember-before-reset'])->save();
    $other = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $otherBefore = $other->getAttributes();
    $membershipsBefore = DB::table('account_memberships')->orderBy('id')->get()->toArray();
    $this->admin->createToken('old administrator token');
    $other->createToken('unrelated owner token');
    foreach ([['id' => 'reset-admin-a', 'user_id' => $this->admin->id], ['id' => 'reset-admin-b', 'user_id' => $this->admin->id], ['id' => 'reset-owner', 'user_id' => $other->id]] as $session) {
        DB::table('sessions')->insert($session + ['payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    }
    $token = Password::broker()->createToken($this->admin);
    $payload = ['email' => $this->admin->email, 'token' => $token,
        'password' => 'admin-password-after-reset', 'password_confirmation' => 'admin-password-after-reset'];
    Event::fake([PasswordReset::class]);

    $this->postJson('/reset-password', $payload)->assertOk()->assertExactJson([
        'message' => 'Your password has been reset. Please sign in with your new password.',
    ]);

    $updated = $this->admin->fresh();
    expect(Hash::check($payload['password'], $updated->password))->toBeTrue();
    expect($updated->remember_token)->not->toBe('remember-before-reset');
    expect($updated->admin_status_revision)->toBe(5);
    expect($updated->platform_role)->toBe('platform_admin');
    expect($updated->status)->toBe($status);
    expect($updated->memberships()->exists())->toBeFalse();
    expect(DB::table('account_memberships')->orderBy('id')->get()->toArray())->toEqual($membershipsBefore);
    expect($other->fresh()->getAttributes())->toBe($otherBefore);
    expect($updated->tokens()->count())->toBe(0);
    expect($other->tokens()->count())->toBe(1);
    $this->assertDatabaseMissing('sessions', ['user_id' => $updated->id]);
    $this->assertDatabaseHas('sessions', ['id' => 'reset-owner', 'user_id' => $other->id]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $updated->email]);
    Event::assertDispatchedTimes(PasswordReset::class, 1);
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->id === $updated->id);
    $this->assertGuest('web');

    $this->postJson('/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);
    expect($updated->fresh()->admin_status_revision)->toBe(5);
    Event::assertDispatchedTimes(PasswordReset::class, 1);
    $this->resetBrowserGuards();
    if ($status === 'active') {
        $this->postJson('/admin/login', ['email' => $updated->email, 'password' => 'admin-password-before-reset'])->assertUnprocessable();
        $this->postJson('/admin/login', ['email' => $updated->email, 'password' => $payload['password']])->assertOk();
        expect($this->app['session']->get('platform_admin_auth'))->toBe(['id' => $updated->id, 'revision' => 5]);
        $this->getJson('/api/v1/admin/me')->assertOk();
    } else {
        $this->postJson('/admin/login', ['email' => $updated->email, 'password' => $payload['password']])->assertForbidden();
        $this->assertGuest('web');
    }
})->with(['active', 'suspended']);

it('rejects unsuccessful admin resets without changing credentials epoch tokens or sessions', function (string $scenario) {
    $before = $this->admin->fresh()->getAttributes();
    $accessToken = $this->admin->createToken('retained on failed reset');
    DB::table('sessions')->insert(['id' => 'retained-admin-session', 'user_id' => $this->admin->id,
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    $token = Password::broker()->createToken($this->admin);
    $payload = ['email' => $this->admin->email, 'token' => $token,
        'password' => 'admin-password-after-reset', 'password_confirmation' => 'admin-password-after-reset'];
    $error = 'email';
    if ($scenario === 'invalid token') {
        $payload['token'] = 'incorrect-token';
    } elseif ($scenario === 'expired token') {
        DB::table('password_reset_tokens')->where('email', $this->admin->email)
            ->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);
    } elseif ($scenario === 'wrong email') {
        $other = User::factory()->create(['platform_role' => 'platform_admin']);
        $payload['email'] = $other->email;
    } else {
        $payload['password_confirmation'] = 'mismatched-password';
        $error = 'password';
    }
    Event::fake([PasswordReset::class]);

    $this->postJson('/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors([$error]);
    expect($this->admin->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseHas('sessions', ['id' => 'retained-admin-session', 'user_id' => $this->admin->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $accessToken->accessToken->id]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $this->admin->email]);
    if ($scenario !== 'expired token') {
        expect(Password::broker()->tokenExists($this->admin, $token))->toBeTrue();
    }
    Event::assertNotDispatched(PasswordReset::class);
    $this->assertGuest('web');
})->with(['invalid token', 'expired token', 'wrong email', 'password mismatch']);

it('rejects a pre-reset administrator session even when its non-database payload survives', function () {
    config(['session.driver' => 'array']);
    $this->app['session']->forgetDrivers();
    $token = Password::broker()->createToken($this->admin);
    $this->postJson('/reset-password', ['email' => $this->admin->email, 'token' => $token,
        'password' => 'admin-password-after-reset', 'password_confirmation' => 'admin-password-after-reset'])->assertOk();

    // File, Redis, or cookie payloads may survive deletion from the sessions table.
    $this->resetBrowserGuards();
    $this->actingAs($this->admin->fresh(), 'web')->withSession([
        'platform_admin_auth' => ['id' => $this->admin->id, 'revision' => 4],
    ]);
    $this->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->assertGuest('web');
    $this->resetBrowserGuards();
    $this->postJson('/admin/login', ['email' => $this->admin->email, 'password' => 'admin-password-after-reset'])->assertOk();
    expect($this->app['session']->get('platform_admin_auth'))->toBe(['id' => $this->admin->id, 'revision' => 5]);
    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('allows recovery while signed in as a different user without switching their identity', function () {
    Notification::fake();
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $before = $owner->getAttributes();
    $this->actingAs($owner, 'web');
    $this->postJson('/forgot-password', ['email' => $this->admin->email])->assertOk();
    Notification::assertSentTo($this->admin, ResetPassword::class, function (ResetPassword $notification) {
        $this->postJson('/reset-password', ['email' => $this->admin->email, 'token' => $notification->token,
            'password' => 'admin-password-after-reset', 'password_confirmation' => 'admin-password-after-reset'])->assertOk();

        return true;
    });

    $this->assertAuthenticatedAs($owner, 'web');
    expect($owner->fresh()->getAttributes())->toBe($before);
    expect($this->admin->fresh()->admin_status_revision)->toBe(5);
    expect(Hash::check('admin-password-after-reset', $this->admin->fresh()->password))->toBeTrue();
});

it('accepts recovery for the signed-in administrator and requires a fresh login afterwards', function () {
    Notification::fake();
    $this->postJson('/admin/login', ['email' => $this->admin->email, 'password' => 'admin-password-before-reset'])->assertOk();
    $this->postJson('/forgot-password', ['email' => $this->admin->email])->assertOk();
    Notification::assertSentTo($this->admin, ResetPassword::class, function (ResetPassword $notification) {
        $this->postJson('/reset-password', ['email' => $this->admin->email, 'token' => $notification->token,
            'password' => 'admin-password-after-reset', 'password_confirmation' => 'admin-password-after-reset'])->assertOk();

        return true;
    });

    // Refresh the in-memory test guard as the browser's next request would reload its user.
    $this->actingAs($this->admin->fresh(), 'web');
    $this->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->assertGuest('web');
});

it('keeps an owners administrator epoch unchanged when resetting through the shared endpoint', function () {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
    $revision = $owner->admin_status_revision;
    $token = Password::broker()->createToken($owner);

    $this->postJson('/reset-password', ['email' => $owner->email, 'token' => $token,
        'password' => 'owner-password-after-reset', 'password_confirmation' => 'owner-password-after-reset'])->assertOk();

    expect($owner->fresh()->admin_status_revision)->toBe($revision);
    expect($this->admin->fresh()->admin_status_revision)->toBe(4);
    $this->assertGuest('web');
});
