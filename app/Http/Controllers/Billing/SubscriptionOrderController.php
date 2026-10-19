<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateSubscriptionOrderRequest;
use App\Http\Requests\Billing\OrderDirectoryRequest;
use App\Http\Resources\Billing\SubscriptionOrderResource;
use App\Models\Billing\SubscriptionOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Billing\CreateSubscriptionOrder;
use App\Services\Billing\ExpireSubscriptionOrder;
use App\Services\Payments\PayMongo\PayMongoConfigurationException;
use App\Services\Payments\PayMongo\PayMongoPayloadException;
use App\Services\Payments\PayMongo\PayMongoRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionOrderController extends Controller
{
    public function index(OrderDirectoryRequest $request, Store $store): AnonymousResourceCollection
    {
        $this->ensureOwner($request, $store);

        return SubscriptionOrderResource::collection(SubscriptionOrder::where('account_id', $store->account_id)
            ->with('subscription')->orderByDesc('id')->paginate($request->integer('per_page', 20)));
    }

    public function store(CreateSubscriptionOrderRequest $request, Store $store, CreateSubscriptionOrder $create): JsonResponse
    {
        try {
            $result = $create->handle($store, $request->user(), $request->validated());
        } catch (PayMongoConfigurationException) {
            return $this->unavailable('billing_not_configured', 'Online payment is not configured yet. Please try again later.');
        }
        $order = $result['order']->load('subscription');
        $status = in_array($order->payment_status, ['unknown', 'creating'], true) ? 202 : ($result['replayed'] ? 200 : 201);

        return response()->json(['data' => new SubscriptionOrderResource($order), 'replayed' => $result['replayed']], $status);
    }

    public function show(Request $request, Store $store, string $order): SubscriptionOrderResource
    {
        $this->ensureOwner($request, $store);

        return new SubscriptionOrderResource($this->find($store, $order));
    }

    public function byKey(Request $request, Store $store, string $key): SubscriptionOrderResource
    {
        $this->ensureOwner($request, $store);

        return new SubscriptionOrderResource(SubscriptionOrder::where('account_id', $store->account_id)
            ->where('request_key', $key)->with('subscription')->firstOrFail());
    }

    public function expire(Request $request, Store $store, string $order, ExpireSubscriptionOrder $expire): SubscriptionOrderResource|JsonResponse
    {
        try {
            return new SubscriptionOrderResource($expire->handle($store, $request->user(), $this->find($store, $order))->load('subscription'));
        } catch (PayMongoConfigurationException|PayMongoRequestException|PayMongoPayloadException) {
            return $this->unavailable('billing_provider_unavailable', 'Payment status could not be confirmed. Keep this order and try checking again.');
        }
    }

    private function find(Store $store, string $uuid): SubscriptionOrder
    {
        return SubscriptionOrder::where('account_id', $store->account_id)->where('uuid', $uuid)->with('subscription')->firstOrFail();
    }

    private function unavailable(string $code, string $message): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], 503);
    }

    private function ensureOwner(Request $request, Store $store): void
    {
        $actor = User::whereKey($request->user()->id)->where('status', 'active')->first();
        abort_unless($actor && ! $actor->isPlatformAdmin()
            && $store->account()->where('owner_user_id', $actor->id)->where('status', 'active')->exists(), 404);
    }
}
