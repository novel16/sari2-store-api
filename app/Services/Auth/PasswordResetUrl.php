<?php

namespace App\Services\Auth;

use App\Models\User;

class PasswordResetUrl
{
    public function forUser(User $user, string $token): string
    {
        // The saved role and trusted deployment configuration choose the app.
        // Never accept a request origin or redirect URL when mailing a reset token.
        $base = config($user->isPlatformAdmin() ? 'app.admin_url' : 'app.frontend_url');

        return rtrim($base, '/').'/reset-password?'.http_build_query([
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
