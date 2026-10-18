<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdministratorSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->status === 'active', 403, 'Your account is unavailable. Please contact support.');
        if ($user->isPlatformAdmin()) {
            AdministratorSession::ensureCurrent($request, $user);
        }
        abort_unless($user->isPlatformAdmin() || $user->hasActiveWorkspace(), 403, 'Your store workspace is unavailable. Please contact support.');

        return $next($request);
    }
}
