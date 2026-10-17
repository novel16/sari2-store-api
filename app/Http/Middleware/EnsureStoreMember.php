<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->route('store');
        abort_unless($store instanceof Store, 404);

        $membership = $request->user()->memberships()
            ->where('account_id', $store->account_id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->first();

        abort_unless($membership, 404);
        abort_unless($store->status === 'active' && $store->account->status === 'active', 403, 'This store is currently unavailable.');

        return $next($request);
    }
}
