<?php

use App\Models\User;
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
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
});

it('returns the same forgot-password response for registered unknown and broker-throttled addresses', function () {
    Notification::fake();
    $user = $this->createOwner();
    $existing = $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
    $unknown = $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertOk();
    $throttled = $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();

    expect($unknown->json())->toBe($existing->json());
    expect($throttled->json())->toBe($existing->json());
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
    $this->assertGuest('web');
});

it('stores only the hashed reset token and points the notification at the client reset page', function () {
    Notification::fake();
    config(['app.frontend_url' => 'http://localhost:3000']);
    $user = $this->createOwner();
    $this->postJson('/forgot-password', ['email' => $user->email])->assertOk()
        ->assertJsonMissingPath('token');

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        expect($stored)->not->toBe($notification->token);
        expect(Hash::check($notification->token, $stored))->toBeTrue();
        $url = $notification->toMail($user)->actionUrl;
        expect($url)->toStartWith('http://localhost:3000/reset-password?');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect($query)->toBe(['token' => $notification->token, 'email' => $user->email]);

        return true;
    });
});

it('resets the password once and revokes only that users database sessions and tokens', function () {
    $user = $this->createOwner();
    $other = User::factory()->create();
    $user->forceFill(['remember_token' => 'remember-token-before-reset'])->save();
    $user->createToken('old-token');
    $other->createToken('unrelated-token');
    foreach ([['id' => 'owner-session-a', 'user_id' => $user->id], ['id' => 'owner-session-b', 'user_id' => $user->id], ['id' => 'other-session', 'user_id' => $other->id]] as $session) {
        DB::table('sessions')->insert($session + ['payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    }
    $token = Password::broker()->createToken($user);
    Event::fake([PasswordReset::class]);
    $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password-456', 'password_confirmation' => 'new-secure-password-456'];

    $this->postJson('/reset-password', $payload)->assertOk();
    expect(Hash::check($payload['password'], $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->remember_token)->not->toBe('remember-token-before-reset');
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $other->id]);
    expect($user->tokens()->count())->toBe(0);
    expect($other->tokens()->count())->toBe(1);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->id === $user->id);
    $this->assertGuest('web');

    $this->postJson('/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson('/login', ['email' => $user->email, 'password' => 'secure-password-123'])->assertUnprocessable();
    $this->postJson('/login', ['email' => $user->email, 'password' => $payload['password']])->assertOk();
});

it('rejects invalid expired and wrong-email reset tokens without changing credentials', function (string $scenario) {
    $user = $this->createOwner();
    $oldHash = $user->password;
    $token = Password::broker()->createToken($user);
    $email = $user->email;
    if ($scenario === 'invalid') {
        $token = 'an-invalid-reset-token';
    } elseif ($scenario === 'expired') {
        DB::table('password_reset_tokens')->where('email', $email)->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);
    } else {
        $email = 'unrelated@example.test';
    }

    $this->postJson('/reset-password', ['email' => $email, 'token' => $token, 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    expect($user->fresh()->password)->toBe($oldHash);
    $this->assertGuest('web');
})->with(['invalid', 'expired', 'wrong email']);

it('requires password confirmation before consuming a valid reset token', function () {
    $user = $this->createOwner();
    $token = Password::broker()->createToken($user);
    $this->postJson('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'replacement-password', 'password_confirmation' => 'different-password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    expect(Password::broker()->tokenExists($user, $token))->toBeTrue();
    expect(Hash::check('secure-password-123', $user->fresh()->password))->toBeTrue();
});
