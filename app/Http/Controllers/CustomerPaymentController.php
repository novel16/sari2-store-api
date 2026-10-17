<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\RepaymentRequest;
use App\Http\Resources\CreditLedgerResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Store;
use App\Services\Customers\RecordRepayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CustomerPaymentController extends Controller
{
    public function store(RepaymentRequest $request, Store $store, Customer $customer, RecordRepayment $record): JsonResponse
    {
        $result = $record->handle($store, $customer, $request->user(), $request->validated());

        return response()->json(['data' => new CreditLedgerResource($result['entry']), 'customer' => new CustomerResource($result['customer']->loadExists('ledgerEntries')),
            'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function byKey(Store $store, Customer $customer, string $key): JsonResponse
    {
        abort_unless(Str::isUuid($key), 404);
        $entry = $customer->ledgerEntries()->where('store_id', $store->id)->where('entry_type', 'payment')->where('request_key', Str::lower($key))->with(['sale', 'actor'])->firstOrFail();

        return response()->json(['data' => new CreditLedgerResource($entry), 'customer' => new CustomerResource($customer->loadExists('ledgerEntries'))]);
    }

    public function show(Store $store, Customer $customer, string $payment): JsonResponse
    {
        $entry = $customer->ledgerEntries()->where('store_id', $store->id)->where('entry_type', 'payment')->whereKey($payment)->with(['sale', 'actor'])->firstOrFail();

        return response()->json(['data' => new CreditLedgerResource($entry), 'customer' => new CustomerResource($customer->loadExists('ledgerEntries')),
            'store' => $store->only(['id', 'name', 'address', 'phone'])]);
    }
}
