<?php

namespace App\Services\Admin\Administrators;

use App\Exceptions\CheckoutConflict;
use App\Models\Admin\AdministratorEvent;
use App\Models\User;
use App\Services\Admin\AdministratorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class AdministratorService
{
    public function __construct(private AdministratorReview $reviews) {}

    public function previewCreate(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input) {
            $this->lockRoster();
            $actor = AdministratorAccess::lock($actor);
            $this->emailAvailable($input['email']);

            return $this->reviews->create(['kind' => 'create', 'name' => $input['name'],
                'email' => $input['email'], 'reason' => $input['reason']], $actor);
        }, 3);
    }

    public function previewStatus(User $target, User $actor, array $input): array
    {
        return DB::transaction(function () use ($target, $actor, $input) {
            $revision = $this->lockRoster();
            [$actor, $target] = $this->lockUsers($actor, $target);
            $this->canChange($target, $actor, $input['status']);

            return $this->reviews->create($this->statusSnapshot($target, $input['status'], $input['reason']),
                $actor, (int) $target->admin_status_revision, $revision);
        }, 3);
    }

    public function create(User $actor, array $input): array
    {
        return $this->mutate('create', null, $actor, $input);
    }

    public function changeStatus(User $target, User $actor, array $input): array
    {
        return $this->mutate('status', $target, $actor, $input);
    }

    public function byKey(User $actor, string $key): array
    {
        abort_unless(Str::isUuid($key), 404);
        AdministratorAccess::fresh($actor);
        $event = AdministratorEvent::where('actor_user_id', $actor->id)
            ->where('request_key', Str::lower($key))->firstOrFail();

        return ['event' => $event, 'replayed' => true];
    }

    /** The interactive console is the bootstrap path; it cannot promote an existing owner. */
    public function provisionFromConsole(array $input): User
    {
        try {
            return DB::transaction(function () use ($input) {
                $revision = $this->lockRoster();
                $this->emailAvailable($input['email']);
                $user = $this->newAdministrator($input['name'], $input['email'], $input['password']);
                $key = (string) Str::uuid();
                $this->record($user, null, 'created', null, 'Provisioned through the interactive console.',
                    $key, hash('sha256', 'console:'.$key), null);
                $this->advanceRoster($revision);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            $this->emailUnavailable();
        }
    }

    private function mutate(string $kind, ?User $target, User $actor, array $input): array
    {
        $key = Str::lower($input['request_key']);
        $reviewHash = hash('sha256', $input['review_token']);
        // Passwords are proof/input only. Neither credentials nor credential hashes enter review/audit fingerprints.
        $requestHash = hash('sha256', json_encode([$actor->id, $kind, $target?->id, $reviewHash], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($kind, $target, $actor, $input, $key, $reviewHash, $requestHash) {
                $revision = $this->lockRoster();
                if ($target) {
                    [$actor, $target] = $this->lockUsers($actor, $target);
                } else {
                    $actor = AdministratorAccess::lock($actor);
                }
                $this->passwordProof($actor, $input['current_password']);
                $previous = AdministratorEvent::where('request_key', $key)->first();
                if ($previous) {
                    return $this->replay($previous, $requestHash);
                }
                if (AdministratorEvent::where('review_hash', $reviewHash)->exists()) {
                    $this->conflict('administrator_review_used', 'This review was already confirmed. Check administrator history.');
                }
                $review = $this->reviews->read($input['review_token'], $actor, $kind, $target?->id);
                $this->checkReview($review, $actor);
                $snapshot = $review['snapshot'];
                if ($kind === 'create') {
                    $this->emailAvailable($snapshot['email']);
                    $target = $this->newAdministrator($snapshot['name'], $snapshot['email'], $input['password']);
                    $fromStatus = null;
                } else {
                    if ($review['target_revision'] !== (int) $target->admin_status_revision || $review['roster_revision'] !== $revision
                        || $snapshot !== $this->statusSnapshot($target, $snapshot['to_status'], $snapshot['reason'])) {
                        $this->stale();
                    }
                    $this->canChange($target, $actor, $snapshot['to_status']);
                    $fromStatus = $target->status;
                    $target->forceFill(['status' => $snapshot['to_status'],
                        'admin_status_revision' => (int) $target->admin_status_revision + 1]);
                    if ($target->status === 'suspended') {
                        $target->setRememberToken(null);
                    }
                    $target->save();
                    if ($target->status === 'suspended') {
                        // Both tables share the application database, so audit failure rolls revocation back too.
                        DB::table('sessions')->where('user_id', $target->id)->delete();
                        $target->tokens()->delete();
                        DB::table('password_reset_tokens')->where('email', $target->email)->delete();
                    }
                }
                $event = $this->record($target, $actor, $kind === 'create' ? 'created' : 'status_changed',
                    $fromStatus, $snapshot['reason'], $key, $requestHash, $reviewHash);
                $this->advanceRoster($revision);

                return ['event' => $event, 'replayed' => false];
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            // The unique indexes also protect keys against writers outside the roster transaction.
            AdministratorAccess::fresh($actor);
            if ($previous = AdministratorEvent::where('request_key', $key)->first()) {
                return $this->replay($previous, $requestHash);
            }
            if (AdministratorEvent::where('review_hash', $reviewHash)->exists()) {
                $this->conflict('administrator_review_used', 'This review was already confirmed. Check administrator history.');
            }
            if ($kind === 'create') {
                $this->emailUnavailable();
            }
            throw $error;
        }
    }

    private function checkReview(array $review, User $actor): void
    {
        if (CarbonImmutable::parse($review['expires_at'])->lte(CarbonImmutable::now('UTC'))) {
            $this->conflict('administrator_review_expired', 'This review expired. Review the administrator change again.');
        }
        if ($review['actor_revision'] !== (int) $actor->admin_status_revision) {
            $this->stale();
        }
    }

    private function lockRoster(): int
    {
        $row = DB::table('admin_management_locks')->where('id', 1)->lockForUpdate()->first();
        if (! $row) {
            throw new LogicException('The administrator management lock is missing. Run the application migrations.');
        }

        return (int) $row->revision;
    }

    private function advanceRoster(int $revision): void
    {
        DB::table('admin_management_locks')->where('id', 1)->update(['revision' => $revision + 1]);
    }

    private function lockUsers(User $actor, User $target): array
    {
        $users = User::whereIn('id', array_unique([$actor->id, $target->id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = AdministratorAccess::ensure($users->get($actor->id), $actor);
        $target = $users->get($target->id);
        abort_unless($target?->isPlatformAdmin(), 404);

        return [$actor, $target];
    }

    private function canChange(User $target, User $actor, string $status): void
    {
        if ($target->status === $status) {
            $this->conflict('administrator_no_change', 'This administrator already has the requested status. Refresh its details.');
        }
        if ($status === 'suspended' && $target->id === $actor->id) {
            $this->conflict('administrator_self_suspend', 'You cannot suspend your own administrator account.');
        }
        if ($status === 'suspended' && $this->activeCount() <= 1) {
            $this->conflict('administrator_last_active', 'The last active platform administrator must remain available.');
        }
    }

    private function activeCount(): int
    {
        return User::where('platform_role', 'platform_admin')->where('status', 'active')->count();
    }

    private function statusSnapshot(User $target, string $status, string $reason): array
    {
        return ['kind' => 'status', 'administrator' => ['id' => $target->id, 'name' => $target->name,
            'email' => $target->email, 'status' => $target->status], 'from_status' => $target->status,
            'to_status' => $status, 'reason' => $reason, 'active_administrators' => $this->activeCount()];
    }

    private function newAdministrator(string $name, string $email, string $password): User
    {
        $user = new User;
        $user->fill(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        $user->forceFill(['platform_role' => 'platform_admin', 'status' => 'active', 'admin_status_revision' => 0]);
        $user->save();

        return $user;
    }

    private function record(User $target, ?User $actor, string $action, ?string $fromStatus, string $reason,
        string $key, string $requestHash, ?string $reviewHash): AdministratorEvent
    {
        return AdministratorEvent::create(['administrator_user_id' => $target->id,
            'administrator_name_snapshot' => $target->name, 'administrator_email_snapshot' => $target->email,
            'action' => $action, 'from_status' => $fromStatus, 'to_status' => $target->status, 'reason' => $reason,
            'actor_user_id' => $actor?->id, 'actor_name_snapshot' => $actor?->name ?? 'Console',
            'request_key' => $key, 'request_hash' => $requestHash, 'review_hash' => $reviewHash,
            'result_snapshot' => ['id' => $target->id, 'name' => $target->name, 'email' => $target->email,
                'status' => $target->status, 'created_at' => $target->created_at?->toJSON(), 'updated_at' => $target->updated_at?->toJSON()]]);
    }

    private function passwordProof(User $actor, string $password): void
    {
        if (! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }
    }

    private function emailAvailable(string $email): void
    {
        if (User::where('email', $email)->exists()) {
            $this->emailUnavailable();
        }
    }

    private function emailUnavailable(): never
    {
        throw ValidationException::withMessages(['email' => ['This email address is already in use. Choose a different email address.']]);
    }

    private function replay(AdministratorEvent $event, string $requestHash): array
    {
        if (! hash_equals($event->request_hash, $requestHash)) {
            $this->conflict('administrator_key_conflict', 'This request key was used with different details. Check administrator history.');
        }

        return ['event' => $event, 'replayed' => true];
    }

    private function stale(): never
    {
        $this->conflict('administrator_review_stale', 'The administrator or roster changed after review. Refresh and review again.');
    }

    private function conflict(string $code, string $message): never
    {
        throw new CheckoutConflict($code, $message);
    }
}
