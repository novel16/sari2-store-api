<?php

namespace App\Services\Settings;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateAccount
{
    public function profile(User $actor, array $input, ?string $sessionId): void
    {
        try {
            $saved = DB::transaction(function () use ($actor, $input, $sessionId) {
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $emailChanged = $user->email !== $input['email'];
                if ($emailChanged) {
                    $this->verifyPassword($user, $input['current_password'] ?? '');
                    DB::table('password_reset_tokens')->whereIn('email', [$user->email, $input['email']])->delete();
                    // Change the password hash without changing the password so
                    // AuthenticateSession also invalidates non-database sessions.
                    $user->forceFill(['password' => Hash::make($input['current_password']),
                        'remember_token' => Str::random(60), 'email_verified_at' => null]);
                    $this->revokeOtherSessions($user, $sessionId);
                }
                $user->fill(['name' => trim($input['name']), 'email' => $input['email']])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['This email address is already in use.']]);
        }
        $actor->setRawAttributes($saved->getAttributes(), true);
    }

    public function password(User $actor, array $input, ?string $sessionId): void
    {
        $saved = DB::transaction(function () use ($actor, $input, $sessionId) {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->verifyPassword($user, $input['current_password']);
            $user->forceFill(['password' => Hash::make($input['password']), 'remember_token' => Str::random(60)])->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $this->revokeOtherSessions($user, $sessionId);

            return $user;
        });
        $actor->setRawAttributes($saved->getAttributes(), true);
    }

    private function verifyPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }
    }

    private function revokeOtherSessions(User $user, ?string $currentSession): void
    {
        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)->when($currentSession, fn ($query) => $query->where('id', '!=', $currentSession))->delete();
        $user->tokens()->delete();
    }
}
