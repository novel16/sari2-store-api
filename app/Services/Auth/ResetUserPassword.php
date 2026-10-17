<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResetUserPassword
{
    public function handle(User $user, string $password, string $token): void
    {
        $updatedUser = DB::transaction(function () use ($user, $password, $token): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            // Recheck after acquiring the lock so a concurrent reset cannot reuse a token.
            if (! Password::broker()->tokenExists($lockedUser, $token)) {
                throw ValidationException::withMessages([
                    'email' => ['This password reset link is invalid or has expired.'],
                ]);
            }

            $lockedUser->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $lockedUser->id)
                ->delete();

            $lockedUser->tokens()->delete();
            Password::broker()->deleteToken($lockedUser);

            return $lockedUser;
        });

        event(new PasswordReset($updatedUser));
    }
}
