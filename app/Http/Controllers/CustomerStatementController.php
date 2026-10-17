<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\StatementRequest;
use App\Http\Resources\CreditLedgerResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CustomerStatementController extends Controller
{
    public function __invoke(StatementRequest $request, Store $store, Customer $customer): AnonymousResourceCollection
    {
        return DB::transaction(function () use ($request, $store, $customer) {
            // Every balance mutation takes this same store lock. Capture a stable
            // upper entry ID, then paginate immutable entries through that ID.
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $customer = $store->customers()->withExists('ledgerEntries')->findOrFail($customer->id);
            $through = $request->has('through_id') ? $request->integer('through_id') : (int) $customer->ledgerEntries()->max('id');
            $last = $through ? $customer->ledgerEntries()->findOrFail($through) : null;
            $customer->balance_centavos = $last?->balance_after_centavos ?? 0;
            $entries = $customer->ledgerEntries()->where('id', '<=', $through);
            $totals = (clone $entries)->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'opening_balance' THEN amount_centavos ELSE 0 END),0) AS opening_centavos,
                COALESCE(SUM(CASE WHEN entry_type = 'charge' THEN amount_centavos ELSE 0 END),0) AS charges_centavos,
                COALESCE(SUM(CASE WHEN entry_type = 'payment' THEN amount_centavos ELSE 0 END),0) AS payments_centavos,
                COALESCE(SUM(CASE WHEN entry_type = 'reversal' THEN amount_centavos ELSE 0 END),0) AS reversals_centavos")->first();

            return CreditLedgerResource::collection($entries->with(['sale', 'actor', 'reversal.sale'])->orderBy('id')->paginate($request->integer('per_page', 100)))
                ->additional(['customer' => new CustomerResource($customer), 'store' => $store->only(['id', 'name', 'address', 'phone']),
                    'snapshot_id' => $through, 'generated_at' => now()->toIso8601String(),
                    'totals' => collect($totals->getAttributes())->map(fn ($value) => (string) $value)->all()]);
        }, 3);
    }
}
