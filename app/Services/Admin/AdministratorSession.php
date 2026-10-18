<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdministratorSession
{
    public const KEY = 'platform_admin_auth';

    public static function record(Request $request, User $user): void
    {
        if ($user->isPlatformAdmin()) {
            $request->session()->put(self::KEY, ['id' => $user->id, 'revision' => (int) $user->admin_status_revision]);
        } else {
            $request->session()->forget(self::KEY);
        }
    }

    public static function ensureCurrent(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        if (! $request->hasSession() || ! $guard->check() || (int) $guard->id() !== $user->id) {
            return; // Personal access tokens are separately revoked on suspension.
        }

        $saved = $request->session()->get(self::KEY);
        $revision = (int) $user->admin_status_revision;
        if ($saved === null && ($revision === 0 || $guard->viaRemember())) {
            // Adopt pre-migration sessions only at the initial revision. A valid
            // recaller also proves a token issued after the latest suspension.
            self::record($request, $user);

            return;
        }
        if (is_array($saved) && ($saved['id'] ?? null) === $user->id && ($saved['revision'] ?? null) === $revision) {
            return;
        }

        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        abort(401, 'Your administrator session expired. Please sign in again.');
    }
}
