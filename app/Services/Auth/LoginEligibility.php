<?php

namespace App\Services\Auth;

use App\Models\User;

class LoginEligibility
{
    public function allows(User $user, bool $platformAdmin): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        if ($platformAdmin) {
            return $user->isPlatformAdmin();
        }

        if ($user->isPlatformAdmin()) {
            return false;
        }

        return $user->hasActiveWorkspace();
    }
}
