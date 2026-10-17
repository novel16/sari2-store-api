<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

it('has no public administrator registration endpoint', function () {
    $this->postJson('/admin/register', $this->ownerRegistration(['platform_role' => 'platform_admin']))->assertNotFound();
    $this->assertDatabaseCount('users', 0);
});

it('refuses non-interactive administrator creation', function () {
    $this->artisan('app:create-platform-admin', ['--no-interaction' => true])
        ->expectsOutputToContain('Run this command interactively.')
        ->assertFailed();
    $this->assertDatabaseCount('users', 0);
});

it('rejects unsafe administrator passwords before provisioning', function (string $password) {
    $this->artisan('app:create-platform-admin')
        ->expectsQuestion('Name', 'Test Administrator')
        ->expectsQuestion('Email address', 'admin@example.test')
        ->expectsQuestion('Password (8–72 characters, at most 72 UTF-8 bytes)', $password)
        ->expectsQuestion('Confirm password', $password)
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
})->with([
    'bcrypt byte overflow' => [str_repeat('a', 73)],
    'embedded null byte' => ["admin-password\0suffix"],
]);

it('does not provision an administrator when the final confirmation is declined', function () {
    $this->artisan('app:create-platform-admin')
        ->expectsQuestion('Name', 'Test Administrator')
        ->expectsQuestion('Email address', 'admin@example.test')
        ->expectsQuestion('Password (8–72 characters, at most 72 UTF-8 bytes)', 'test-only-password')
        ->expectsQuestion('Confirm password', 'test-only-password')
        ->expectsConfirmation('Create this platform administrator?', 'no')
        ->expectsOutputToContain('No administrator was created.')
        ->assertSuccessful();
    $this->assertDatabaseCount('users', 0);
});

it('provisions only an administrator with a hashed password after explicit confirmation', function () {
    $this->artisan('app:create-platform-admin')
        ->expectsQuestion('Name', 'Test Administrator')
        ->expectsQuestion('Email address', 'ADMIN@example.test')
        ->expectsQuestion('Password (8–72 characters, at most 72 UTF-8 bytes)', 'test-only-password')
        ->expectsQuestion('Confirm password', 'test-only-password')
        ->expectsConfirmation('Create this platform administrator?', 'yes')
        ->assertSuccessful();

    $admin = User::where('email', 'admin@example.test')->firstOrFail();
    expect($admin->platform_role)->toBe('platform_admin');
    expect($admin->status)->toBe('active');
    expect(Hash::check('test-only-password', $admin->password))->toBeTrue();
    $this->assertDatabaseCount('accounts', 0);
    $this->assertDatabaseCount('stores', 0);
    $this->withHeader('Origin', 'http://localhost:3001')->postJson('/admin/login', ['email' => $admin->email, 'password' => 'test-only-password'])->assertOk();
});
