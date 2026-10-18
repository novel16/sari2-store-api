<?php

use App\Models\Admin\AdministratorEvent;
use App\Models\User;
use App\Services\Auth\RegisterStoreOwner;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Support\AuthTestHelpers;
use Tests\Support\CountsDatabaseQueries;

uses(AuthTestHelpers::class, CountsDatabaseQueries::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active',
        'password' => Hash::make('admin-password-123')]);
    $this->target = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->base = '/api/v1/admin/administrators';
    $this->actingAs($this->admin, 'web');
});

function administratorCreationReview($test, array $input = []): array
{
    return $test->postJson($test->base.'/creation-preview', array_replace([
        'name' => 'Support Administrator', 'email' => 'support@example.test', 'reason' => 'Approved support administrator access',
    ], $input))->assertOk()->json('data');
}

function administratorStatusReview($test, ?User $target = null, string $status = 'suspended'): array
{
    return $test->postJson($test->base.'/'.($target ?? $test->target)->id.'/status-preview', [
        'status' => $status, 'reason' => 'Verified administrator access request',
    ])->assertOk()->json('data');
}

function administratorConfirmCreation($test, array $review, ?string $key = null, array $overrides = [])
{
    return $test->postJson($test->base, array_replace([
        'request_key' => $key ?? (string) Str::uuid(), 'review_token' => $review['review_token'],
        'current_password' => 'admin-password-123', 'password' => 'new-admin-password-123',
        'password_confirmation' => 'new-admin-password-123',
    ], $overrides));
}

function administratorConfirmStatus($test, array $review, ?string $key = null, ?User $target = null, array $overrides = [])
{
    return $test->postJson($test->base.'/'.($target ?? $test->target)->id.'/status-changes', array_replace([
        'request_key' => $key ?? (string) Str::uuid(), 'review_token' => $review['review_token'],
        'current_password' => 'admin-password-123',
    ], $overrides));
}

it('restricts all administrator management endpoints to active platform administrators', function (string $actor) {
    $this->resetBrowserGuards();
    if ($actor === 'owner') {
        $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration());
        $this->actingAs($owner, 'web');
    } elseif ($actor === 'suspended administrator') {
        $this->admin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->admin, 'web');
    }
    $expected = $actor === 'guest' ? 401 : 403;
    foreach ([$this->base, $this->base.'/'.$this->target->id, $this->base.'/'.$this->target->id.'/history',
        $this->base.'/operations/by-key/'.Str::uuid()] as $url) {
        $this->getJson($url)->assertStatus($expected);
    }
    foreach ([$this->base, $this->base.'/creation-preview', $this->base.'/'.$this->target->id.'/status-preview',
        $this->base.'/'.$this->target->id.'/status-changes'] as $url) {
        $this->postJson($url, [])->assertStatus($expected);
    }
    $this->assertDatabaseCount('admin_administrator_events', 0);
})->with(['guest', 'owner', 'suspended administrator']);

it('lists and searches only administrators with stable pagination and no sensitive fields', function () {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration(['name' => 'Private Owner', 'email' => 'private-owner@example.test']));
    $this->target->forceFill(['name' => 'Searchable Support', 'email' => 'searchable@example.test', 'status' => 'suspended'])->save();
    $this->getJson($this->base.'?q=searchable&status=suspended')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->target->id)->assertJsonMissingPath('data.0.password')
        ->assertJsonMissingPath('data.0.remember_token')->assertJsonMissingPath('data.0.accounts')
        ->assertJsonMissingPath('data.0.admin_status_revision');
    $this->getJson($this->base.'?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson($this->base.'?q=private-owner')->assertOk()->assertJsonCount(0, 'data');
    foreach (['', '/history', '/status-preview'] as $suffix) {
        $url = $this->base.'/'.$owner->id.$suffix;
        ($suffix === '/status-preview' ? $this->postJson($url, ['status' => 'suspended', 'reason' => 'Invalid owner promotion']) : $this->getJson($url))->assertNotFound();
    }
    $this->getJson($this->base.'?status=deleted')->assertUnprocessable();
    $this->getJson($this->base.'?per_page=101')->assertUnprocessable();
});

it('creates an independent administrator only after review and current password proof', function () {
    $review = administratorCreationReview($this, ['name' => '  New Administrator  ', 'email' => ' NEW-ADMIN@EXAMPLE.TEST ',
        'reason' => '  Approved new support member  ']);
    expect($review)->toMatchArray(['kind' => 'create', 'name' => 'New Administrator', 'email' => 'new-admin@example.test', 'reason' => 'Approved new support member']);
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('admin_administrator_events', 0);
    $response = administratorConfirmCreation($this, $review)->assertCreated()->assertJsonPath('meta.replayed', false)
        ->assertJsonPath('data.name', 'New Administrator')->assertJsonPath('data.email', 'new-admin@example.test')
        ->assertJsonPath('data.status', 'active')->assertJsonPath('event.action', 'created')
        ->assertJsonPath('event.from_status', null)->assertJsonPath('event.to_status', 'active')
        ->assertJsonPath('event.actor.id', $this->admin->id)->assertJsonPath('event.reason', 'Approved new support member');
    $created = User::findOrFail($response->json('data.id'));
    expect($created->platform_role)->toBe('platform_admin');
    expect(Hash::check('new-admin-password-123', $created->password))->toBeTrue();
    expect($created->memberships()->count())->toBe(0);
    foreach (['accounts', 'stores', 'account_memberships'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->resetBrowserGuards();
    $this->postJson('/admin/login', ['email' => $created->email, 'password' => 'new-admin-password-123'])->assertOk();
    $this->getJson('/api/v1/admin/dashboard')->assertOk();
});

it('never embeds credentials in reviews API resources or audit events', function () {
    $review = administratorCreationReview($this);
    $cleartext = Crypt::decryptString($review['review_token']);
    expect($cleartext)->not->toContain('password', 'admin-password-123', 'new-admin-password-123');
    $response = administratorConfirmCreation($this, $review)->assertCreated();
    expect($response->getContent())->not->toContain('password', 'remember_token', 'request_hash', 'review_hash', 'review_token');
    expect(json_encode(DB::table('admin_administrator_events')->first(), JSON_THROW_ON_ERROR))
        ->not->toContain('admin-password-123', 'new-admin-password-123', User::findOrFail($response->json('data.id'))->password);
    $this->getJson($this->base.'/'.$response->json('data.id').'/history')->assertOk()
        ->assertJsonMissingPath('data.0.request_hash')->assertJsonMissingPath('data.0.review_hash')
        ->assertJsonMissingPath('data.0.review_token')->assertJsonMissingPath('data.0.password');
});

it('rejects unsafe creation credentials without creating an administrator', function (array $input, string $field) {
    $review = administratorCreationReview($this);
    administratorConfirmCreation($this, $review, overrides: $input)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('admin_administrator_events', 0);
})->with([
    [['current_password' => null], 'current_password'], [['current_password' => 'wrong-password'], 'current_password'],
    [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
    [['password' => "abc\0password", 'password_confirmation' => "abc\0password"], 'password'],
    [['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)], 'password'],
    [['password_confirmation' => 'different-password'], 'password'],
    [['platform_role' => 'owner'], 'platform_role'], [['account_id' => 3], 'account_id'],
    [['actor_user_id' => 999], 'actor_user_id'], [['status' => 'suspended'], 'status'],
]);

it('requires bounded identity and reason fields for administrator creation reviews', function (array $input, string $field) {
    $this->postJson($this->base.'/creation-preview', array_replace([
        'name' => 'Support Admin', 'email' => 'support@example.test', 'reason' => 'New support administrator',
    ], $input))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['name' => ' '], 'name'], [['name' => str_repeat('n', 256)], 'name'], [['email' => ['invalid']], 'email'],
    [['email' => 'invalid'], 'email'], [['reason' => 'ab'], 'reason'], [['reason' => '  '], 'reason'],
    [['reason' => str_repeat('r', 501)], 'reason'], [['password' => 'must-not-enter-review'], 'password'],
    [['current_password' => 'must-not-enter-review'], 'current_password'], [['platform_role' => 'platform_admin'], 'platform_role'],
]);

it('never promotes an owner or duplicates any existing email including normalized case', function () {
    $owner = app(RegisterStoreOwner::class)->handle($this->ownerRegistration(['email' => 'owner@example.test']));
    foreach ([' OWNER@EXAMPLE.TEST ', strtoupper($this->target->email)] as $email) {
        $this->postJson($this->base.'/creation-preview', ['name' => 'New admin', 'email' => $email, 'reason' => 'Support access requested'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }
    $review = administratorCreationReview($this);
    User::factory()->create(['email' => $review['email']]);
    administratorConfirmCreation($this, $review)->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($owner->fresh()->platform_role)->toBe('user');
    $this->assertDatabaseCount('admin_administrator_events', 0);
});

it('requires a valid review for creation and status changes', function () {
    $this->postJson($this->base, ['request_key' => (string) Str::uuid(), 'current_password' => 'admin-password-123',
        'password' => 'new-admin-password-123', 'password_confirmation' => 'new-admin-password-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('review_token');
    $this->postJson($this->base.'/'.$this->target->id.'/status-changes', ['request_key' => (string) Str::uuid(), 'current_password' => 'admin-password-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('review_token');
    $this->assertDatabaseCount('admin_administrator_events', 0);
});

it('binds administrator reviews to kind target and actor and rejects tampering', function () {
    $creation = administratorCreationReview($this);
    $status = administratorStatusReview($this);
    administratorConfirmStatus($this, $creation)->assertConflict()->assertJsonPath('code', 'administrator_review_invalid');
    administratorConfirmCreation($this, $status)->assertConflict()->assertJsonPath('code', 'administrator_review_invalid');
    $other = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    administratorConfirmStatus($this, $status, target: $other)->assertConflict()->assertJsonPath('code', 'administrator_review_invalid');
    administratorConfirmCreation($this, ['review_token' => $creation['review_token'].'corrupt'])->assertConflict();
    $this->resetBrowserGuards();
    $other->forceFill(['password' => Hash::make('admin-password-123')])->save();
    $this->actingAs($other, 'web');
    administratorConfirmCreation($this, $creation)->assertConflict()->assertJsonPath('code', 'administrator_review_invalid');
    administratorConfirmStatus($this, $status)->assertConflict()->assertJsonPath('code', 'administrator_review_invalid');
    $this->assertDatabaseCount('admin_administrator_events', 0);
});

it('rejects administrator reviews at their expiry boundary', function (string $kind) {
    $review = $kind === 'create' ? administratorCreationReview($this) : administratorStatusReview($this);
    $this->travel(10)->minutes();
    ($kind === 'create' ? administratorConfirmCreation($this, $review) : administratorConfirmStatus($this, $review))
        ->assertConflict()->assertJsonPath('code', 'administrator_review_expired');
    $this->assertDatabaseCount('admin_administrator_events', 0);
})->with(['create', 'status']);

it('replays one creation after expiry without changing the initial password and confines recovery to its actor', function () {
    $review = administratorCreationReview($this);
    $key = (string) Str::uuid();
    $response = administratorConfirmCreation($this, $review, $key)->assertCreated();
    $id = $response->json('data.id');
    $this->travel(11)->minutes();
    administratorConfirmCreation($this, $review, strtoupper($key), ['password' => 'different-password-123', 'password_confirmation' => 'different-password-123'])
        ->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $id)->assertJsonPath('event.id', $response->json('event.id'));
    expect(Hash::check('new-admin-password-123', User::findOrFail($id)->password))->toBeTrue();
    $this->getJson($this->base.'/operations/by-key/'.strtoupper($key))->assertOk()->assertJsonPath('data.id', $id);
    administratorConfirmCreation($this, $review, $key, ['current_password' => 'wrong-password'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    administratorConfirmCreation($this, $review)->assertConflict()->assertJsonPath('code', 'administrator_review_used');
    administratorConfirmCreation($this, administratorCreationReview($this, ['email' => 'another@example.test']), $key)
        ->assertConflict()->assertJsonPath('code', 'administrator_key_conflict');
    $this->resetBrowserGuards();
    $this->actingAs($this->target, 'web')->getJson($this->base.'/operations/by-key/'.$key)->assertNotFound();
    $this->assertDatabaseCount('admin_administrator_events', 1);
});

it('suspends and reactivates administrators while revoking sessions tokens reset links and remember cookies', function () {
    $before = $this->target->remember_token;
    $this->target->createToken('existing session');
    Password::broker()->createToken($this->target);
    foreach ([['id' => 'target-session', 'user_id' => $this->target->id], ['id' => 'unrelated-session', 'user_id' => $this->admin->id]] as $row) {
        DB::table('sessions')->insert($row + ['payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    }
    $review = administratorStatusReview($this);
    expect($review)->toMatchArray(['kind' => 'status', 'from_status' => 'active', 'to_status' => 'suspended']);
    administratorConfirmStatus($this, $review)->assertCreated()->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('event.action', 'status_changed')->assertJsonPath('event.from_status', 'active')
        ->assertJsonPath('event.to_status', 'suspended')->assertJsonPath('event.administrator.id', $this->target->id);
    expect($this->target->fresh()->remember_token)->not->toBe($before);
    expect($this->target->fresh()->admin_status_revision)->toBe(1);
    expect($this->target->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
    $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->target->email]);
    administratorConfirmStatus($this, administratorStatusReview($this, status: 'active'))->assertCreated()->assertJsonPath('data.status', 'active');
    expect($this->target->fresh()->admin_status_revision)->toBe(2);
    expect($this->target->memberships()->count())->toBe(0);
});

it('requires current administrator proof for status confirmation', function () {
    $review = administratorStatusReview($this);
    foreach ([null, 'wrong-password'] as $password) {
        administratorConfirmStatus($this, $review, overrides: ['current_password' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }
    expect($this->target->fresh()->status)->toBe('active');
    $this->assertDatabaseCount('admin_administrator_events', 0);
});

it('rejects self suspension and preserves the last active administrator', function () {
    $this->postJson($this->base.'/'.$this->admin->id.'/status-preview', ['status' => 'suspended', 'reason' => 'Self suspension attempt'])
        ->assertConflict()->assertJsonPath('code', 'administrator_self_suspend');
    $this->target->forceFill(['status' => 'suspended'])->save();
    $this->postJson($this->base.'/'.$this->admin->id.'/status-preview', ['status' => 'suspended', 'reason' => 'Last active administrator attempt'])->assertConflict();
    expect(User::where('platform_role', 'platform_admin')->where('status', 'active')->count())->toBe(1);
    $this->assertDatabaseCount('admin_administrator_events', 0);
});

it('requires changed supported status and a bounded reason', function (array $input, string $field) {
    $this->postJson($this->base.'/'.$this->target->id.'/status-preview', array_replace([
        'status' => 'suspended', 'reason' => 'Support verified access change',
    ], $input))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['status' => 'deleted'], 'status'], [['reason' => ' '], 'reason'], [['reason' => 'ab'], 'reason'],
    [['reason' => str_repeat('r', 501)], 'reason'], [['admin_status_revision' => 99], 'admin_status_revision'],
    [['actor_user_id' => 999], 'actor_user_id'], [['platform_role' => 'owner'], 'platform_role'],
]);

it('rejects a status review when no transition is requested', function () {
    $this->postJson($this->base.'/'.$this->target->id.'/status-preview', ['status' => 'active', 'reason' => 'Already active'])
        ->assertConflict()->assertJsonPath('code', 'administrator_no_change');
});

it('replays status confirmation without reverting a subsequent change and rejects key or token reuse', function () {
    $review = administratorStatusReview($this);
    $key = (string) Str::uuid();
    $first = administratorConfirmStatus($this, $review, $key)->assertCreated();
    administratorConfirmStatus($this, administratorStatusReview($this, status: 'active'))->assertCreated();
    $this->travel(11)->minutes();
    administratorConfirmStatus($this, $review, strtoupper($key))->assertOk()->assertJsonPath('meta.replayed', true)
        ->assertJsonPath('event.id', $first->json('event.id'));
    expect($this->target->fresh()->status)->toBe('active');
    administratorConfirmStatus($this, $review)->assertConflict()->assertJsonPath('code', 'administrator_review_used');
    administratorConfirmStatus($this, administratorStatusReview($this), $key)->assertConflict()->assertJsonPath('code', 'administrator_key_conflict');
    $this->assertDatabaseCount('admin_administrator_events', 2);
});

it('rejects stale status reviews after target identity or roster changes', function (string $change) {
    $review = administratorStatusReview($this);
    if ($change === 'identity') {
        $this->target->update(['name' => 'Renamed after review']);
    } elseif ($change === 'target ABA') {
        administratorConfirmStatus($this, administratorStatusReview($this))->assertCreated();
        administratorConfirmStatus($this, administratorStatusReview($this, status: 'active'))->assertCreated();
    } else {
        administratorConfirmCreation($this, administratorCreationReview($this))->assertCreated();
    }
    administratorConfirmStatus($this, $review)->assertConflict()->assertJsonPath('code', 'administrator_review_stale');
    expect($this->target->fresh()->status)->toBe('active');
})->with(['identity', 'target ABA', 'roster']);

it('rejects creation and status reviews after the actor is suspended and restored', function (string $kind) {
    $review = $kind === 'create' ? administratorCreationReview($this) : administratorStatusReview($this);
    $other = $this->target;
    $other->forceFill(['password' => Hash::make('admin-password-123')])->save();
    $this->resetBrowserGuards();
    $this->actingAs($other, 'web');
    administratorConfirmStatus($this, administratorStatusReview($this, $this->admin), target: $this->admin)->assertCreated();
    administratorConfirmStatus($this, administratorStatusReview($this, $this->admin, 'active'), target: $this->admin)->assertCreated();
    $this->resetBrowserGuards();
    $this->actingAs($this->admin->fresh(), 'web')->withSession(['platform_admin_auth' => ['id' => $this->admin->id, 'revision' => 2]]);
    ($kind === 'create' ? administratorConfirmCreation($this, $review) : administratorConfirmStatus($this, $review))
        ->assertConflict()->assertJsonPath('code', 'administrator_review_stale');
})->with(['create', 'status']);

it('rolls back provisioning suspension and revocation if immutable audit creation fails', function (string $kind) {
    $review = $kind === 'create' ? administratorCreationReview($this) : administratorStatusReview($this);
    $this->target->createToken('must survive rollback');
    $remember = $this->target->remember_token;
    DB::table('sessions')->insert(['id' => 'rollback-session', 'user_id' => $this->target->id,
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
    $revision = DB::table('admin_management_locks')->where('id', 1)->value('revision');
    AdministratorEvent::creating(fn () => throw new RuntimeException('Synthetic administrator audit failure'));
    $this->withoutExceptionHandling();
    try {
        expect(fn () => $kind === 'create' ? administratorConfirmCreation($this, $review) : administratorConfirmStatus($this, $review))
            ->toThrow(RuntimeException::class, 'Synthetic administrator audit failure');
    } finally {
        Event::forget('eloquent.creating: '.AdministratorEvent::class);
        $this->withExceptionHandling();
    }
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('admin_administrator_events', 0);
    expect($this->target->fresh()->status)->toBe('active');
    expect($this->target->fresh()->admin_status_revision)->toBe(0);
    expect($this->target->fresh()->remember_token)->toBe($remember);
    expect($this->target->tokens()->count())->toBe(1);
    $this->assertDatabaseHas('sessions', ['id' => 'rollback-session']);
    expect(DB::table('admin_management_locks')->where('id', 1)->value('revision'))->toBe($revision);
    ($kind === 'create' ? administratorConfirmCreation($this, $review) : administratorConfirmStatus($this, $review))->assertCreated();
})->with(['create', 'status']);

it('keeps audit actor target name and email snapshots immutable with constant history queries', function () {
    $actorName = $this->admin->name;
    $targetName = $this->target->name;
    $targetEmail = $this->target->email;
    $id = administratorConfirmStatus($this, administratorStatusReview($this))->assertCreated()->json('event.id');
    $event = AdministratorEvent::findOrFail($id);
    expect(fn () => $event->update(['reason' => 'Rewritten']))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
    $small = $this->readQueries(fn () => $this->getJson($this->base.'/'.$this->target->id.'/history')->assertOk());
    for ($i = 0; $i < 7; $i++) {
        $this->travel(1)->minutes();
        administratorConfirmStatus($this, administratorStatusReview($this, status: $i % 2 === 0 ? 'active' : 'suspended'))->assertCreated();
    }
    $large = $this->readQueries(fn () => $this->getJson($this->base.'/'.$this->target->id.'/history')->assertOk()->assertJsonCount(8, 'data'));
    expect(count($large))->toBe(count($small));
    $this->admin->update(['name' => 'Renamed actor']);
    $this->target->update(['name' => 'Renamed target', 'email' => 'renamed@example.test']);
    $this->getJson($this->base.'/'.$this->target->id.'/history?per_page=1&page=8')->assertOk()
        ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.actor.name', $actorName)
        ->assertJsonPath('data.0.administrator.name', $targetName)->assertJsonPath('data.0.administrator.email', $targetEmail);
});

it('keeps administrator directory reads constant as the roster grows', function () {
    $small = $this->readQueries(fn () => $this->getJson($this->base)->assertOk()->assertJsonCount(2, 'data'));
    User::factory()->count(15)->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $large = $this->readQueries(fn () => $this->getJson($this->base)->assertOk()->assertJsonCount(17, 'data'));
    expect(count($large))->toBe(count($small));
});

it('keeps an old browser session revoked after administrator reactivation and accepts a fresh sign in', function () {
    $this->resetBrowserGuards();
    $this->postJson('/admin/login', ['email' => $this->admin->email, 'password' => 'admin-password-123'])->assertOk();
    expect($this->app['session']->get('platform_admin_auth'))->toBe(['id' => $this->admin->id, 'revision' => 0]);
    $this->target->forceFill(['password' => Hash::make('admin-password-123')])->save();
    $this->resetBrowserGuards();
    $this->actingAs($this->target, 'web')->withSession(['platform_admin_auth' => ['id' => $this->target->id, 'revision' => 0]]);
    administratorConfirmStatus($this, administratorStatusReview($this, $this->admin), target: $this->admin)->assertCreated();
    administratorConfirmStatus($this, administratorStatusReview($this, $this->admin, 'active'), target: $this->admin)->assertCreated();
    // The stored session payload can outlive a database delete with file or Redis sessions.
    $this->resetBrowserGuards();
    $this->actingAs($this->admin->fresh(), 'web')->withSession(['platform_admin_auth' => ['id' => $this->admin->id, 'revision' => 0]]);
    $this->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->resetBrowserGuards();
    $this->postJson('/admin/login', ['email' => $this->admin->email, 'password' => 'admin-password-123'])->assertOk();
    expect($this->app['session']->get('platform_admin_auth'))->toBe(['id' => $this->admin->id, 'revision' => 2]);
    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('keeps repeated read only administrator reviews outside the credential confirmation allowance', function () {
    $creation = administratorCreationReview($this);
    for ($i = 0; $i < 7; $i++) {
        administratorStatusReview($this);
    }
    administratorConfirmCreation($this, $creation)->assertCreated();
    $this->patchJson('/api/v1/admin/profile', ['name' => 'Support administrator', 'email' => $this->admin->email])->assertOk();
});

it('limits incorrect administrator proof across confirmation endpoints while preserving review and settings access', function () {
    $creation = administratorCreationReview($this);
    $status = administratorStatusReview($this);
    $key = (string) Str::uuid();
    for ($i = 0; $i < 6; $i++) {
        administratorConfirmCreation($this, $creation, $key, ['current_password' => 'incorrect-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }
    administratorConfirmStatus($this, $status)->assertTooManyRequests();
    administratorConfirmCreation($this, $creation, $key)->assertTooManyRequests();
    administratorStatusReview($this);
    $this->patchJson('/api/v1/admin/profile', ['name' => 'Support administrator', 'email' => $this->admin->email])->assertOk();
    $this->assertDatabaseCount('admin_administrator_events', 0);
});

it('keeps the administrator settings proof limit separate from administrator confirmation', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->patchJson('/api/v1/admin/password', ['current_password' => 'incorrect-password',
            'password' => 'changed-admin-password-123', 'password_confirmation' => 'changed-admin-password-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }
    $this->patchJson('/api/v1/admin/profile', ['name' => 'Blocked name', 'email' => $this->admin->email])->assertTooManyRequests();
    administratorConfirmCreation($this, administratorCreationReview($this))->assertCreated();
    expect($this->admin->fresh()->name)->toBe($this->admin->name);
    expect(Hash::check('admin-password-123', $this->admin->fresh()->password))->toBeTrue();
});
