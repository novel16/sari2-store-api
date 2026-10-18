<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\StoreSettingsRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\Store;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function update(StoreSettingsRequest $request, Store $store): AuthUserResource
    {
        DB::transaction(function () use ($request, $store) {
            $locked = StoreWriteAccess::lock($store);
            $input = $request->validated();
            $locked->update(['name' => trim($input['name']), 'address' => $input['address'], 'phone' => $input['phone']]);
        });

        return new AuthUserResource($request->user());
    }

    public function show(Store $store): JsonResponse
    {
        return response()->json(['data' => $store->only([
            'id', 'account_id', 'name', 'slug', 'phone', 'address', 'status',
        ])]);
    }
}
