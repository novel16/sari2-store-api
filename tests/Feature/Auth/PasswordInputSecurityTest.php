<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
});

it('rejects unsafe bcrypt input at registration without creating an account', function (string $password) {
    $this->postJson('/register', $this->ownerRegistration(['password' => $password, 'password_confirmation' => $password]))
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('accounts', 0);
})->with([
    '73 ASCII bytes' => [str_repeat('a', 73)],
    '75 UTF-8 bytes in 25 characters' => [str_repeat('€', 25)],
    'embedded null byte' => ["secure-password-123\0suffix"],
]);

it('rejects unsafe password-reset input without consuming the valid token', function (string $password) {
    $user = $this->createOwner();
    $token = Password::broker()->createToken($user);
    $this->postJson('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    expect(Password::broker()->tokenExists($user, $token))->toBeTrue();
    expect(Hash::check('secure-password-123', $user->fresh()->password))->toBeTrue();
})->with([
    '73 ASCII bytes' => [str_repeat('a', 73)],
    '75 UTF-8 bytes in 25 characters' => [str_repeat('€', 25)],
    'embedded null byte' => ["secure-password-123\0suffix"],
]);

it('rejects unsafe login input before checking truncated bcrypt credentials', function (string $password) {
    $user = $this->createOwner();
    $this->postJson('/login', ['email' => $user->email, 'password' => $password])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $this->assertGuest('web');
})->with([
    '73 ASCII bytes' => [str_repeat('a', 73)],
    '75 UTF-8 bytes in 25 characters' => [str_repeat('€', 25)],
    'embedded null byte after the valid password' => ["secure-password-123\0suffix"],
]);

it('accepts a 72-byte password but rejects that valid prefix with an appended suffix', function () {
    $password = str_repeat('a', 72);
    $user = $this->createOwner(['password' => $password, 'password_confirmation' => $password]);
    $this->postJson('/login', ['email' => $user->email, 'password' => $password.'extra'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $this->assertGuest('web');
    $this->postJson('/login', ['email' => $user->email, 'password' => $password])->assertOk();
});

it('accepts multibyte passwords at exactly the 72-byte boundary', function () {
    $password = str_repeat('€', 24);
    $this->postJson('/register', $this->ownerRegistration(['password' => $password, 'password_confirmation' => $password]))
        ->assertCreated();
});
