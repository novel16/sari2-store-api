<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\Auth\RegisterStoreOwner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function store(RegisterRequest $request, RegisterStoreOwner $registration): JsonResponse
    {
        $user = $registration->handle($request->validated());

        event(new Registered($user));
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        return (new AuthUserResource($user))->response()->setStatusCode(201);
    }
}
