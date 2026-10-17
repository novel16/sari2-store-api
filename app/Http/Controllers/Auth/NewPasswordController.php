<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Auth\ResetUserPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    public function store(ResetPasswordRequest $request, ResetUserPassword $reset): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->validated(),
            fn (User $user, string $password) => $reset->handle($user, $password, $request->string('token')->toString()),
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been reset. Please sign in with your new password.',
        ]);
    }
}
