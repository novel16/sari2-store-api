<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DirectoryRequest;
use App\Http\Resources\Admin\AccountResource;
use App\Models\Account;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function index(DirectoryRequest $request): AnonymousResourceCollection
    {
        $query = Account::with('owner')->withCount(['stores', 'memberships']);
        if ($request->input('status', 'all') !== 'all') {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhereHas('owner', fn ($owner) => $owner->where('name', 'like', $term)->orWhere('email', 'like', $term)));
        }

        return AccountResource::collection($query->latest()->orderByDesc('id')->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account->load('owner')->loadCount(['stores', 'memberships']));
    }
}
