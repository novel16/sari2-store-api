<?php

namespace App\Services\Admin\Status;

use App\Exceptions\CheckoutConflict;
use App\Models\Account;
use App\Models\Admin\StatusChange;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdministratorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StatusChangeService
{
    public function __construct(private StatusReview $reviews) {}

    public function history(Account|Store $target): Builder
    {
        return $this->forTarget($target)->orderByDesc('id');
    }

    public function preview(Account|Store $target, User $actor, array $input): array
    {
        return DB::transaction(function () use ($target, $actor, $input) {
            [$target, $account] = $this->lock($target);
            $actor = $this->actor($actor, true);
            $this->canChange($target, $account, $input['status']);
            $snapshot = $this->snapshot($target, $account, $input['status'], trim($input['reason']));

            return $this->reviews->create($snapshot, $actor, (int) $target->status_revision,
                $target instanceof Store ? (int) $account->status_revision : null);
        }, 3);
    }

    public function change(Account|Store $target, User $actor, array $input): array
    {
        $key = Str::lower($input['request_key']);
        $reviewHash = hash('sha256', $input['review_token']);
        $requestHash = hash('sha256', json_encode([$actor->id, $this->type($target), $target->id, $reviewHash], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($target, $actor, $input, $key, $reviewHash, $requestHash) {
                [$target, $account] = $this->lock($target);
                $actor = $this->actor($actor, true);
                $previous = StatusChange::where('request_key', $key)->first();
                if ($previous) {
                    return $this->replay($previous, $requestHash);
                }
                if (StatusChange::where('review_hash', $reviewHash)->exists()) {
                    $this->conflict('status_review_used', 'This review was already confirmed. Check the status history.');
                }
                $review = $this->reviews->read($input['review_token'], $actor, $this->type($target), $target->id);
                if (CarbonImmutable::parse($review['expires_at'])->lte(CarbonImmutable::now('UTC'))) {
                    $this->conflict('status_review_expired', 'This review expired. Review the status change again.');
                }
                $snapshot = $review['snapshot'];
                $parentRevision = $target instanceof Store ? (int) $account->status_revision : null;
                if ($review['revision'] !== (int) $target->status_revision || $review['parent_revision'] !== $parentRevision
                    || $snapshot !== $this->snapshot($target, $account, $snapshot['to_status'], $snapshot['reason'])) {
                    $this->conflict('status_review_stale', 'The account or store changed after review. Refresh and review again.');
                }
                $this->canChange($target, $account, $snapshot['to_status']);
                $target->forceFill(['status' => $snapshot['to_status'], 'status_revision' => (int) $target->status_revision + 1])->save();
                $change = StatusChange::create(['account_id' => $account->id,
                    'store_id' => $target instanceof Store ? $target->id : null, 'target_type' => $this->type($target),
                    'target_name_snapshot' => $snapshot['target_name'], 'from_status' => $snapshot['from_status'],
                    'to_status' => $snapshot['to_status'], 'reason' => $snapshot['reason'],
                    'actor_user_id' => $actor->id, 'actor_name_snapshot' => $actor->name,
                    'request_key' => $key, 'request_hash' => $requestHash, 'review_hash' => $reviewHash]);

                return ['change' => $change, 'replayed' => false];
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            // The unique constraints also protect request keys shared across different accounts.
            $previous = StatusChange::where('request_key', $key)->first();
            if ($previous) {
                return $this->replay($previous, $requestHash);
            }
            if (StatusChange::where('review_hash', $reviewHash)->exists()) {
                $this->conflict('status_review_used', 'This review was already confirmed. Check the status history.');
            }
            throw $error;
        }
    }

    public function byKey(Account|Store $target, User $actor, string $key): array
    {
        abort_unless(Str::isUuid($key), 404);
        $this->actor($actor);

        return ['change' => $this->forTarget($target)->where('actor_user_id', $actor->id)
            ->where('request_key', Str::lower($key))->firstOrFail(), 'replayed' => true];
    }

    private function lock(Account|Store $target): array
    {
        $accountId = $target instanceof Account ? $target->id : $target->account_id;
        $account = Account::whereKey($accountId)->lockForUpdate()->firstOrFail();
        $target = $target instanceof Account ? $account : Store::where('account_id', $account->id)
            ->whereKey($target->id)->lockForUpdate()->firstOrFail();

        return [$target, $account];
    }

    private function actor(User $actor, bool $lock = false): User
    {
        return $lock ? AdministratorAccess::lock($actor) : AdministratorAccess::fresh($actor);
    }

    private function canChange(Account|Store $target, Account $account, string $status): void
    {
        if ($target->status === $status) {
            $this->conflict('status_no_change', 'This account or store already has the requested status. Refresh its details.');
        }
        if ($target instanceof Store && $status === 'active' && $account->status !== 'active') {
            $this->conflict('status_parent_suspended', 'Reactivate the account before reactivating this store.');
        }
    }

    private function snapshot(Account|Store $target, Account $account, string $toStatus, string $reason): array
    {
        if ($target instanceof Account) {
            $counts = Store::where('account_id', $account->id)->selectRaw('COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS suspended_count', ['active', 'suspended'])->first();
            $total = (int) $counts->total;
            $active = (int) $counts->active_count;
            $suspended = (int) $counts->suspended_count;
        } else {
            $total = 1;
            $active = (int) ($target->status === 'active');
            $suspended = (int) ($target->status === 'suspended');
        }
        $ownerEligible = User::whereKey($account->owner_user_id)->where('status', 'active')
            ->whereHas('memberships', fn (Builder $query) => $query->where('account_id', $account->id)
                ->where('role', 'owner')->where('status', 'active'))->exists();
        $workspaceAvailable = $ownerEligible && $toStatus === 'active'
            && ($target instanceof Account ? $active > 0 : $account->status === 'active');

        return ['target_type' => $this->type($target), 'target_id' => $target->id, 'target_name' => $target->name,
            'account_id' => $account->id, 'from_status' => $target->status, 'to_status' => $toStatus,
            'reason' => $reason, 'parent_status' => $target instanceof Store ? $account->status : null,
            'impact' => ['affected_store_count' => $total, 'active_store_count' => $active,
                'suspended_store_count' => $suspended, 'owner_access_blocked' => ! $workspaceAvailable,
                'child_statuses_preserved' => true]];
    }

    private function type(Account|Store $target): string
    {
        return $target instanceof Account ? 'account' : 'store';
    }

    private function forTarget(Account|Store $target): Builder
    {
        return StatusChange::where('target_type', $this->type($target))->where('account_id', $target instanceof Account ? $target->id : $target->account_id)
            ->when($target instanceof Store, fn (Builder $query) => $query->where('store_id', $target->id));
    }

    private function replay(StatusChange $change, string $hash): array
    {
        if (! hash_equals($change->request_hash, $hash)) {
            $this->conflict('status_key_conflict', 'This request key was used with different details. Check status history before continuing.');
        }

        return ['change' => $change, 'replayed' => true];
    }

    private function conflict(string $code, string $message): never
    {
        throw new CheckoutConflict($code, $message);
    }
}
