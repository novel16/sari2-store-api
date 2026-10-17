<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Support\SalesTestHelpers;

uses(SalesTestHelpers::class);
beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    $this->owner = $this->createOwner();
    $this->store = $this->ownerStore($this->owner);
    $this->actingAs($this->owner, 'web');
});

it('updates the store and profile while keeping old sale snapshots', function () {
    $product = $this->createProduct($this->store);
    $sale = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales', $this->checkoutData($product))->assertCreated()->json('data');
    $this->patchJson('/api/v1/stores/'.$this->store->id, ['name' => 'New store', 'address' => 'New address', 'phone' => '09123456789'])->assertOk()->assertJsonPath('data.accounts.0.stores.0.name', 'New store');
    $this->patchJson('/api/v1/me/profile', ['name' => 'New owner', 'email' => $this->owner->email])->assertOk()->assertJsonPath('data.name', 'New owner');
    $this->getJson('/api/v1/stores/'.$this->store->id.'/sales/'.$sale['id'])->assertOk()->assertJsonPath('data.store.name', $sale['store']['name'])->assertJsonPath('data.sold_by.name', $sale['sold_by']['name']);
});

it('requires current password for email changes and invalidates old reset tokens', function () {
    $old = $this->owner->email;
    Password::broker()->createToken($this->owner);
    $input = ['name' => $this->owner->name, 'email' => 'new-address@example.test'];
    $this->patchJson('/api/v1/me/profile', $input)->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->patchJson('/api/v1/me/profile', [...$input, 'current_password' => 'wrong-password'])->assertUnprocessable();
    $this->patchJson('/api/v1/me/profile', [...$input, 'current_password' => 'secure-password-123'])->assertOk()->assertJsonPath('data.email', $input['email']);
    expect($this->owner->fresh()->email)->toBe($input['email']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $old]);
    $this->getJson('/api/v1/me')->assertOk();
});

it('changes password with current proof and revokes other sessions tokens and reset links', function () {
    $other = User::factory()->create();
    foreach ([['id' => 'owner-other-device', 'user_id' => $this->owner->id], ['id' => 'unrelated', 'user_id' => $other->id]] as $row) {
        DB::table('sessions')->insert($row + ['payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    }
    $this->owner->createToken('old');
    Password::broker()->createToken($this->owner);
    $input = ['current_password' => 'secure-password-123', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
    $this->patchJson('/api/v1/me/password', [...$input, 'current_password' => 'wrong-password'])->assertUnprocessable();
    $this->patchJson('/api/v1/me/password', $input)->assertOk();
    expect(Hash::check('new-secure-password', $this->owner->fresh()->password))->toBeTrue();
    $this->assertDatabaseMissing('sessions', ['id' => 'owner-other-device']);
    $this->assertDatabaseHas('sessions', ['id' => 'unrelated']);
    expect($this->owner->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->owner->email]);
    $this->getJson('/api/v1/me')->assertOk();
});

it('prevents cross-store settings edits and duplicate sign-in email', function () {
    $other = User::factory()->create();
    $this->patchJson('/api/v1/me/profile', ['name' => 'Owner', 'email' => $other->email, 'current_password' => 'secure-password-123'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->patchJson('/api/v1/stores/999999', ['name' => 'Foreign', 'address' => null, 'phone' => null])->assertNotFound();
});

it('rejects invalid password changes', function ($override) {
    $this->patchJson('/api/v1/me/password', [...['current_password' => 'secure-password-123', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'], ...$override])->assertUnprocessable();
})->with(array_map(fn ($input) => [$input], [['password' => 'short', 'password_confirmation' => 'short'], ['password_confirmation' => 'different'], ['password' => str_repeat('a', 73)], ['password' => "abc\0defgh"], ['password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123']]));

it('rejects privilege or stock injection in store and profile updates', function ($field) {
    $this->patchJson('/api/v1/me/profile', ['name' => 'Owner', 'email' => $this->owner->email, $field => 'injected'])->assertUnprocessable();
})->with(['platform_role', 'status', 'user_id', 'account_id', 'store_id', 'email_verified_at']);

it('requires authentication for settings', function () {
    $this->actingAs($this->owner, 'web')->postJson('/logout')->assertOk();
    $this->resetBrowserGuards();
    $this->patchJson('/api/v1/me/profile', [])->assertUnauthorized();
    $this->patchJson('/api/v1/me/password', [])->assertUnauthorized();
    $this->patchJson('/api/v1/stores/'.$this->store->id, [])->assertUnauthorized();
});

it('rejects non-string profile identity fields before database uniqueness checks', function () {
    $this->patchJson('/api/v1/me/profile', ['name' => 'Owner', 'email' => ['invalid'], 'current_password' => 'secure-password-123'])->assertUnprocessable()->assertJsonValidationErrors('email');
});
