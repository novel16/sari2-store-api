<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\CustomerListRequest;
use App\Http\Requests\Customers\CustomerStatusRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\CreditLedgerResource;
use App\Http\Resources\CustomerResource;
use App\Models\CreditLedgerEntry;
use App\Models\Customer;
use App\Models\Store;
use App\Services\Customers\CreateCustomer;
use App\Services\Stores\StoreWriteAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(CustomerListRequest $request, Store $store): AnonymousResourceCollection
    {
        $query = $store->customers()->withExists('ledgerEntries');
        $totals = $store->customers()->selectRaw('COUNT(*) as customers, COALESCE(SUM(balance_centavos),0) as outstanding_centavos, COALESCE(SUM(CASE WHEN balance_centavos > 0 THEN 1 ELSE 0 END),0) as with_balance')->first();
        if ($request->input('status', 'active') !== 'all') {
            $query->where('status', $request->input('status', 'active'));
        }
        $collected = CreditLedgerEntry::where('store_id', $store->id)->where('entry_type', 'payment')->sum('amount_centavos');
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('phone', 'like', $term));
        }
        if ($request->input('balance') === 'outstanding') {
            $query->where('balance_centavos', '>', 0);
        }
        if ($request->input('balance') === 'settled') {
            $query->where('balance_centavos', 0);
        }

        return CustomerResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString())
            ->additional(['meta' => ['summary' => ['customers' => (int) $totals->customers, 'outstanding_centavos' => (string) $totals->outstanding_centavos, 'with_balance' => (int) $totals->with_balance, 'collections_centavos' => (string) $collected]]]);
    }

    public function store(StoreCustomerRequest $request, Store $store, CreateCustomer $create): JsonResponse
    {
        $result = $create->handle($store, $request->validated());

        return response()->json(['data' => new CustomerResource($result['customer']->loadExists('ledgerEntries')), 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function ledger(CustomerListRequest $request, Store $store, Customer $customer): AnonymousResourceCollection
    {
        return CreditLedgerResource::collection($customer->ledgerEntries()->with(['sale', 'actor', 'reversal.sale'])->orderByDesc('id')->paginate($request->integer('per_page', 20)))
            ->additional(['customer' => new CustomerResource($customer->loadExists('ledgerEntries'))]);
    }

    public function show(Store $store, Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->loadExists('ledgerEntries'));
    }

    public function update(UpdateCustomerRequest $request, Store $store, Customer $customer): CustomerResource
    {
        return new CustomerResource($this->change($store, $customer, $request->safe()->only(['name', 'phone', 'notes'])));
    }

    public function status(CustomerStatusRequest $request, Store $store, Customer $customer): CustomerResource
    {
        return new CustomerResource($this->change($store, $customer, $request->safe()->only(['status'])));
    }

    private function change(Store $store, Customer $customer, array $attributes): Customer
    {
        return DB::transaction(function () use ($store, $customer, $attributes) {
            $store = StoreWriteAccess::lock($store);
            $customer = $store->customers()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $customer->fill($attributes)->save();

            return $customer->loadExists('ledgerEntries');
        }, 3);
    }
}
