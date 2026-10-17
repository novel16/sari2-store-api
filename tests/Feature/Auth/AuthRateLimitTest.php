<?php

use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
});

it('limits login attempts per normalized email and ip while allowing a different email', function () {
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->postJson('/login', ['email' => 'blocked@example.test', 'password' => 'incorrect-password'])->assertUnprocessable();
    }
    $this->postJson('/login', ['email' => ' BLOCKED@example.test ', 'password' => 'incorrect-password'])
        ->assertTooManyRequests()->assertHeader('Retry-After');
    $this->postJson('/login', ['email' => 'other@example.test', 'password' => 'incorrect-password'])->assertUnprocessable();
});

it('shares the email login limit with admin login to prevent endpoint switching', function () {
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->postJson('/login', ['email' => 'blocked@example.test', 'password' => 'incorrect-password'])->assertUnprocessable();
    }
    $this->postJson('/admin/login', ['email' => 'blocked@example.test', 'password' => 'incorrect-password'])->assertTooManyRequests();
});

it('caps login traffic per ip even when the email changes on every attempt', function () {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->postJson('/login', ['email' => "attempt-{$attempt}@example.test", 'password' => 'incorrect-password'])->assertUnprocessable();
    }
    $this->postJson('/login', ['email' => 'last-attempt@example.test', 'password' => 'incorrect-password'])->assertTooManyRequests();
});

it('limits public registration to five requests per minute per ip', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/register', [])->assertUnprocessable();
    }
    $this->postJson('/register', [])->assertTooManyRequests();
    $this->assertDatabaseCount('users', 0);
});

it('limits password recovery requests across forgot and reset endpoints', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/forgot-password', ['email' => "missing-{$attempt}@example.test"])->assertOk();
    }
    $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertTooManyRequests();
    $this->postJson('/reset-password', [])->assertTooManyRequests();
});

it('allows registration again after the rate limit window expires', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/register', [])->assertUnprocessable();
    }
    $this->postJson('/register', [])->assertTooManyRequests();
    $this->travel(61)->seconds();
    $this->postJson('/register', [])->assertUnprocessable();
});

it('validates malformed login emails without throwing in the throttle middleware', function (mixed $email) {
    $this->postJson('/login', ['email' => $email, 'password' => 'incorrect-password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->assertGuest('web');
})->with([
    'array' => [['array@example.test']],
    'nested array' => [['nested' => ['email']]],
    'number' => [123],
    'null' => [null],
]);
