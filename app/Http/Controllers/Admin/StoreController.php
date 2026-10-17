<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DirectoryRequest;
use App\Http\Resources\Admin\StoreResource;
use App\Models\Store;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreController extends Controller
{
    public function index(DirectoryRequest $request): AnonymousResourceCollection
    {
        $query = Store::with('account.owner');
        if ($request->input('status', 'all') !== 'all') {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhereHas('account', fn ($account) => $account->where('name', 'like', $term))
                ->orWhereHas('account.owner', fn ($owner) => $owner->where('name', 'like', $term)->orWhere('email', 'like', $term)));
        }

        return StoreResource::collection($query->latest()->orderByDesc('id')->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function show(Store $store): StoreResource
    {
        return new StoreResource($store->load('account.owner'));
    }
}
