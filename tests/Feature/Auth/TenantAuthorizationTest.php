<?php

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
});

it('shows only the authenticated owners account and store in me', function () {
    $owner = $this->createOwner(['email' => 'owner-a@example.test', 'store_name' => 'Store A']);
    $other = $this->createOwner(['email' => 'owner-b@example.test', 'store_name' => 'Store B']);
    $ownStore = $this->ownerStore($owner);
    $otherStore = $this->ownerStore($other);

    $this->actingAs($owner, 'web')->getJson('/api/v1/me')->assertOk()
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.id', $ownStore->account_id)
        ->assertJsonCount(1, 'data.accounts.0.stores')
        ->assertJsonPath('data.accounts.0.stores.0.id', $ownStore->id)
        ->assertJsonMissing(['name' => 'Store B'])
        ->assertJsonMissing(['email' => $other->email]);

    $this->getJson('/api/v1/stores/'.$otherStore->id)->assertNotFound();
    $this->getJson('/api/v1/stores/'.$ownStore->id)->assertOk()->assertJsonPath('data.id', $ownStore->id);
});

it('requires login before exposing a store', function () {
    $owner = $this->createOwner();
    $this->getJson('/api/v1/stores/'.$this->ownerStore($owner)->id)->assertUnauthorized();
});

it('does not grant platform administrators universal access to private store data', function () {
    $owner = $this->createOwner();
    $store = $this->ownerStore($owner);
    $admin = User::factory()->create();
    $admin->forceFill(['platform_role' => 'platform_admin', 'status' => 'active'])->save();

    $this->actingAs($admin, 'web')->getJson('/api/v1/admin/me')->assertOk();
    $this->getJson('/api/v1/stores/'.$store->id)->assertNotFound();
});

it('denies an owner whose membership has been suspended', function () {
    $owner = $this->createOwner();
    $store = $this->ownerStore($owner);
    AccountMembership::where('user_id', $owner->id)->update(['status' => 'suspended']);

    $this->actingAs($owner, 'web')->getJson('/api/v1/me')->assertForbidden();
    expect($this->getJson('/api/v1/stores/'.$store->id)->status())->toBeIn([403, 404]);
    $this->postJson('/logout')->assertOk();
    $this->postJson('/login', ['email' => $owner->email, 'password' => 'secure-password-123'])->assertForbidden();
});

it('denies suspended accounts without exposing their stores', function () {
    $owner = $this->createOwner();
    $store = $this->ownerStore($owner);
    Account::whereKey($store->account_id)->update(['status' => 'suspended']);

    $this->actingAs($owner, 'web')->getJson('/api/v1/me')->assertForbidden();
    $this->getJson('/api/v1/stores/'.$store->id)->assertForbidden();
    $this->postJson('/logout')->assertOk();
    $this->postJson('/login', ['email' => $owner->email, 'password' => 'secure-password-123'])->assertForbidden();
});

it('denies a suspended store even while its account and membership remain active', function () {
    $owner = $this->createOwner();
    $store = $this->ownerStore($owner);
    $store->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($owner, 'web')->getJson('/api/v1/stores/'.$store->id)->assertForbidden();
    $this->getJson('/api/v1/me')->assertForbidden();
    $this->postJson('/logout')->assertOk();
    $this->postJson('/login', ['email' => $owner->email, 'password' => 'secure-password-123'])->assertForbidden();
});

it('does not activate deferred staff access by changing a membership role', function () {
    $owner = $this->createOwner();
    $store = $this->ownerStore($owner);
    AccountMembership::where('user_id', $owner->id)->update(['role' => 'cashier']);

    $this->actingAs($owner, 'web')->getJson('/api/v1/me')->assertForbidden();
    expect($this->getJson('/api/v1/stores/'.$store->id)->status())->toBeIn([403, 404]);
    $this->postJson('/logout')->assertOk();
    $this->postJson('/login', ['email' => $owner->email, 'password' => 'secure-password-123'])->assertForbidden();
});

it('returns the same response for another owners store and a missing store', function () {
    config(['app.debug' => false]);
    $owner = $this->createOwner();
    $other = $this->createOwner();
    $store = $this->ownerStore($other);

    $this->actingAs($owner, 'web');
    $foreign = $this->getJson('/api/v1/stores/'.$store->id)->assertNotFound();
    $missing = $this->getJson('/api/v1/stores/999999999')->assertNotFound();

    expect($foreign->json())->toBe(['message' => 'Resource not found.']);
    expect($missing->json())->toBe($foreign->json());
});

it('does not expose deferred staff workspaces through the owner identity response', function () {
    $owner = $this->createOwner();
    $other = $this->createOwner();
    $ownStore = $this->ownerStore($owner);
    $otherStore = $this->ownerStore($other);
    AccountMembership::create([
        'account_id' => $otherStore->account_id,
        'user_id' => $owner->id,
        'role' => 'cashier',
        'status' => 'active',
    ]);

    $this->actingAs($owner, 'web')->getJson('/api/v1/me')->assertOk()
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.id', $ownStore->account_id);
    $this->getJson('/api/v1/stores/'.$otherStore->id)->assertNotFound();
});
