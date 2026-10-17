<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\ChangePasswordRequest;
use App\Http\Requests\Settings\ProfileSettingsRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\Settings\UpdateAccount;
use Illuminate\Http\JsonResponse;

class AccountSettingsController extends Controller
{
    public function profile(ProfileSettingsRequest $request, UpdateAccount $update): AuthUserResource
    {
        $update->profile($request->user(), $request->validated(), $request->hasSession() ? $request->session()->getId() : null);

        return new AuthUserResource($request->user());
    }

    public function password(ChangePasswordRequest $request, UpdateAccount $update): JsonResponse
    {
        $update->password($request->user(), $request->validated(), $request->hasSession() ? $request->session()->getId() : null);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }
}
