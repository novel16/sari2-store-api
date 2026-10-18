<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AdministratorAccess
{
    /** Call within the surrounding privileged write transaction. */
    public static function lock(User $actor): User
    {
        return self::resolve($actor, true);
    }

    public static function fresh(User $actor): User
    {
        return self::resolve($actor, false);
    }

    public static function ensure(?User $actor, ?User $expected = null): User
    {
        abort_unless($actor && $actor->isPlatformAdmin() && $actor->status === 'active', 403,
            'An active platform administrator is required for this action.');
        abort_if($expected && (int) $expected->admin_status_revision !== (int) $actor->admin_status_revision, 403,
            'Your administrator access changed. Sign in again before continuing.');

        return $actor;
    }

    private static function resolve(User $actor, bool $lock): User
    {
        return self::ensure(User::whereKey($actor->id)->when($lock, fn (Builder $query) => $query->lockForUpdate())->first(), $actor);
    }
}
