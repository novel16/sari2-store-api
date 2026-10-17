<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\OpeningBalanceRequest;
use App\Http\Resources\CreditLedgerResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Store;
use App\Services\Customers\RecordOpeningBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CustomerOpeningBalanceController extends Controller
{
    public function store(OpeningBalanceRequest $request, Store $store, Customer $customer, RecordOpeningBalance $record): JsonResponse
    {
        $result = $record->handle($store, $customer, $request->user(), $request->validated());

        return response()->json(['data' => new CreditLedgerResource($result['entry']), 'customer' => new CustomerResource($result['customer']->loadExists('ledgerEntries')),
            'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function byKey(Store $store, Customer $customer, string $key): JsonResponse
    {
        abort_unless(Str::isUuid($key), 404);
        $entry = $customer->ledgerEntries()->where('entry_type', 'opening_balance')->where('request_key', Str::lower($key))->with(['actor', 'sale'])->firstOrFail();

        return response()->json(['data' => new CreditLedgerResource($entry), 'customer' => new CustomerResource($customer->loadExists('ledgerEntries'))]);
    }
}
