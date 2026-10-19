<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionPaymentRequest;
use App\Http\Resources\Admin\SubscriptionPaymentResource;
use App\Models\Billing\SubscriptionOrder;
use App\Services\Admin\AdministratorAccess;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionPaymentController extends Controller
{
    public function __invoke(SubscriptionPaymentRequest $request): AnonymousResourceCollection
    {
        AdministratorAccess::fresh($request->user());
        $query = SubscriptionOrder::with(['account', 'owner', 'subscription']);
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn ($q) => $q->where('uuid', 'like', $term)
                ->orWhereHas('account', fn ($account) => $account->where('name', 'like', $term))
                ->orWhereHas('owner', fn ($owner) => $owner->where('name', 'like', $term)->orWhere('email', 'like', $term)));
        }
        foreach (['payment_status', 'fulfillment_status'] as $filter) {
            if ($request->input($filter, 'all') !== 'all') {
                $query->where($filter, $request->validated($filter));
            }
        }

        return SubscriptionPaymentResource::collection($query->orderByDesc('id')->paginate($request->integer('per_page', 20)));
    }
}
