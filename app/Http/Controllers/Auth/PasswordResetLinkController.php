<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            Password::broker()->sendResetLink($request->safe()->only(['email']));
        } catch (Throwable $exception) {
            // Delivery failures must not reveal whether a submitted address is registered.
            report($exception);
        }

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link will be sent.',
        ]);
    }
}
