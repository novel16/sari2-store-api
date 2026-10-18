<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\Admin\AdministratorSession;
use App\Services\Auth\LoginEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request, LoginEligibility $eligibility): AuthUserResource
    {
        return $this->authenticate($request, $eligibility, false);
    }

    public function admin(LoginRequest $request, LoginEligibility $eligibility): AuthUserResource
    {
        return $this->authenticate($request, $eligibility, true);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'You have been signed out.']);
    }

    private function authenticate(
        LoginRequest $request,
        LoginEligibility $eligibility,
        bool $platformAdmin,
    ): AuthUserResource {
        $denied = false;
        $authenticated = Auth::guard('web')->attemptWhen(
            $request->safe()->only(['email', 'password']),
            function (User $user) use ($eligibility, $platformAdmin, &$denied): bool {
                $denied = ! $eligibility->allows($user, $platformAdmin);

                return ! $denied;
            },
            $request->boolean('remember'),
        );

        if (! $authenticated) {
            if ($denied) {
                throw new AuthorizationException('This account is not available for this sign-in.');
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        AdministratorSession::record($request, $user);

        return new AuthUserResource($user);
    }
}
