<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuthUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return (new AuthUserResource($request->user()))->response()->setStatusCode(200);
    }
}
