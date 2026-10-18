<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ChangePasswordRequest;
use App\Http\Requests\Settings\ProfileSettingsRequest;
use App\Services\Admin\Profile\UpdateAdministratorProfile;
use Illuminate\Http\JsonResponse;

class AdministratorSettingsController extends Controller
{
    public function profile(ProfileSettingsRequest $request, UpdateAdministratorProfile $update): JsonResponse
    {
        $update->profile($request->user(), $request->validated(), $request->hasSession() ? $request->session()->getId() : null);

        return response()->json(['data' => $request->user()->only([
            'id', 'name', 'email', 'platform_role', 'status', 'created_at', 'updated_at',
        ])]);
    }

    public function password(ChangePasswordRequest $request, UpdateAdministratorProfile $update): JsonResponse
    {
        $update->password($request->user(), $request->validated(), $request->hasSession() ? $request->session()->getId() : null);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }
}
