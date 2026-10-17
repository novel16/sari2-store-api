<?php

namespace App\Services\Sales;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SalesQuery
{
    public function forStore(Store $store, array $filters): Builder
    {
        $query = $store->sales()->getQuery()->where('status', 'completed');
        if (! empty($filters['through_id'])) {
            $query->where('id', '<=', $filters['through_id']);
        }
        if (! empty($filters['q'])) {
            $query->where('sale_number', 'like', '%'.$filters['q'].'%');
        }
        if (! empty($filters['date_from'])) {
            $query->where('completed_at', '>=', Carbon::createFromFormat('!Y-m-d', $filters['date_from'], 'Asia/Manila')->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where('completed_at', '<', Carbon::createFromFormat('!Y-m-d', $filters['date_to'], 'Asia/Manila')->addDay()->utc());
        }

        return $query;
    }

    public function summary(Builder $query): array
    {
        $totals = (clone $query)->whereDoesntHave('reversal')->selectRaw('COUNT(*) AS transactions, COALESCE(SUM(subtotal_centavos), 0) AS gross_sales_centavos,
            COALESCE(SUM(discount_centavos), 0) AS discounts_centavos, COALESCE(SUM(total_centavos), 0) AS net_sales_centavos,
            COALESCE(SUM(amount_paid_centavos), 0) AS cash_collected_centavos')->first();

        return ['transactions' => (int) $totals->transactions,
            'gross_sales_centavos' => (string) $totals->gross_sales_centavos, 'discounts_centavos' => (string) $totals->discounts_centavos,
            'net_sales_centavos' => (string) $totals->net_sales_centavos, 'cash_collected_centavos' => (string) $totals->cash_collected_centavos];
    }
}
