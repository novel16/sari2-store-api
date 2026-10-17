<?php

namespace App\Services\Reports;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class StoreActivity
{
    public const TIMEZONE = 'Asia/Manila';

    public function sales(Store $store, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('sales')->where('store_id', $store->id)->where('status', 'completed')
            ->where('completed_at', '>=', $from->utc()->toDateTimeString())
            ->where('completed_at', '<', $to->addDay()->utc()->toDateTimeString());
    }

    private function repayments(Store $store, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('credit_ledger_entries')->where('store_id', $store->id)
            ->where('entry_type', 'payment')->where('payment_method', 'cash')
            ->where('created_at', '>=', $from->utc()->toDateTimeString())
            ->where('created_at', '<', $to->addDay()->utc()->toDateTimeString());
    }

    private function reversals(Store $store, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('sale_reversals')->where('store_id', $store->id)
            ->where('created_at', '>=', $from->utc()->toDateTimeString())
            ->where('created_at', '<', $to->addDay()->utc()->toDateTimeString());
    }

    public function summary(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = $this->sales($store, $from, $to)->selectRaw('COUNT(*) AS transactions,
            COALESCE(SUM(quantity_total), 0) AS units_sold,
            COALESCE(SUM(subtotal_centavos), 0) AS gross_sales_centavos,
            COALESCE(SUM(discount_centavos), 0) AS discounts_centavos,
            COALESCE(SUM(total_centavos), 0) AS net_sales_centavos,
            COALESCE(SUM(amount_paid_centavos), 0) AS cash_sales_centavos,
            COALESCE(SUM(credit_amount_centavos), 0) AS credit_sales_centavos');
        $payments = $this->repayments($store, $from, $to)->selectRaw('COUNT(*) AS repayment_count,
            COALESCE(SUM(amount_centavos), 0) AS repayments_centavos');
        $returns = $this->reversals($store, $from, $to)->selectRaw('COUNT(*) AS reversal_count,
            COALESCE(SUM(total_centavos), 0) AS reversed_sales_centavos, COALESCE(SUM(cash_refund_centavos), 0) AS cash_refunds_centavos');
        $totals = DB::query()->fromSub($sales, 's')->crossJoinSub($payments, 'p')->crossJoinSub($returns, 'r')
            ->select(['s.*', 'p.repayment_count', 'p.repayments_centavos', 'r.*'])
            ->selectRaw('s.cash_sales_centavos + p.repayments_centavos AS cash_collected_centavos,
                s.net_sales_centavos - r.reversed_sales_centavos AS net_after_reversals_centavos,
                s.cash_sales_centavos + p.repayments_centavos - r.cash_refunds_centavos AS net_cash_collected_centavos')->first();

        return $this->values($totals);
    }

    public function days(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $salesCases = [];
        $paymentCases = [];
        $bindings = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[$date] = ['date' => $date, ...$this->values()];
            $salesCases[] = 'WHEN completed_at < ? THEN ?';
            $paymentCases[] = 'WHEN created_at < ? THEN ?';
            array_push($bindings, $day->addDay()->utc()->toDateTimeString(), $date);
        }
        // Bounded to 366 days by the report request (31 for Dashboard). Carbon
        // computes each UTC boundary, so no database timezone tables are needed.
        $sales = $this->sales($store, $from, $to)
            ->selectRaw('CASE '.implode(' ', $salesCases).' END AS business_date', $bindings)
            ->selectRaw('COUNT(*) AS transactions, SUM(quantity_total) AS units_sold,
                SUM(subtotal_centavos) AS gross_sales_centavos, SUM(discount_centavos) AS discounts_centavos,
                SUM(total_centavos) AS net_sales_centavos, SUM(amount_paid_centavos) AS cash_sales_centavos,
                SUM(credit_amount_centavos) AS credit_sales_centavos, 0 AS repayment_count, 0 AS repayments_centavos,
                0 AS reversal_count, 0 AS reversed_sales_centavos, 0 AS cash_refunds_centavos')
            ->groupBy('business_date');
        $payments = $this->repayments($store, $from, $to)
            ->selectRaw('CASE '.implode(' ', $paymentCases).' END AS business_date', $bindings)
            ->selectRaw('0 AS transactions, 0 AS units_sold, 0 AS gross_sales_centavos, 0 AS discounts_centavos,
                0 AS net_sales_centavos, 0 AS cash_sales_centavos, 0 AS credit_sales_centavos,
                COUNT(*) AS repayment_count, SUM(amount_centavos) AS repayments_centavos,
                0 AS reversal_count, 0 AS reversed_sales_centavos, 0 AS cash_refunds_centavos')->groupBy('business_date');
        $returns = $this->reversals($store, $from, $to)->selectRaw('CASE '.implode(' ', $paymentCases).' END AS business_date', $bindings)
            ->selectRaw('0 AS transactions, 0 AS units_sold, 0 AS gross_sales_centavos, 0 AS discounts_centavos,
                0 AS net_sales_centavos, 0 AS cash_sales_centavos, 0 AS credit_sales_centavos, 0 AS repayment_count, 0 AS repayments_centavos,
                COUNT(*) AS reversal_count, SUM(total_centavos) AS reversed_sales_centavos, SUM(cash_refund_centavos) AS cash_refunds_centavos')->groupBy('business_date');
        $rows = DB::query()->fromSub($sales->unionAll($payments)->unionAll($returns), 'activity')->select('business_date')
            ->selectRaw('SUM(transactions) AS transactions, SUM(units_sold) AS units_sold,
                SUM(gross_sales_centavos) AS gross_sales_centavos, SUM(discounts_centavos) AS discounts_centavos,
                SUM(net_sales_centavos) AS net_sales_centavos, SUM(cash_sales_centavos) AS cash_sales_centavos,
                SUM(credit_sales_centavos) AS credit_sales_centavos, SUM(repayment_count) AS repayment_count,
                SUM(repayments_centavos) AS repayments_centavos,
                SUM(cash_sales_centavos) + SUM(repayments_centavos) AS cash_collected_centavos,
                SUM(reversal_count) AS reversal_count, SUM(reversed_sales_centavos) AS reversed_sales_centavos,
                SUM(cash_refunds_centavos) AS cash_refunds_centavos,
                SUM(net_sales_centavos) - SUM(reversed_sales_centavos) AS net_after_reversals_centavos,
                SUM(cash_sales_centavos) + SUM(repayments_centavos) - SUM(cash_refunds_centavos) AS net_cash_collected_centavos')
            ->groupBy('business_date')->get();
        foreach ($rows as $row) {
            $days[$row->business_date] = ['date' => $row->business_date, ...$this->values($row)];
        }

        return array_values($days);
    }

    public function topProducts(Store $store, CarbonImmutable $from, CarbonImmutable $to, int $limit = 5): array
    {
        $ranked = DB::table('sale_items')->where('store_id', $store->id)
            ->whereIn('sale_id', $this->sales($store, $from, $to)->select('id'))
            ->selectRaw('product_id, SUM(quantity) AS units_sold, SUM(line_total_centavos) AS gross_sales_centavos, MAX(id) AS latest_item_id')
            ->groupBy('product_id')->orderByDesc('units_sold')->orderBy('product_id')->limit($limit);

        return DB::query()->fromSub($ranked, 'ranked')->join('sale_items as item', 'item.id', '=', 'ranked.latest_item_id')
            ->select(['ranked.product_id', 'ranked.units_sold', 'ranked.gross_sales_centavos', 'item.product_name_snapshot', 'item.unit_snapshot'])
            ->orderByDesc('ranked.units_sold')->orderBy('ranked.product_id')->get()->map(fn ($row) => [
                'product_id' => (int) $row->product_id, 'name' => $row->product_name_snapshot,
                'unit' => $row->unit_snapshot, 'units_sold' => (string) $row->units_sold,
                'gross_sales_centavos' => (string) $row->gross_sales_centavos,
            ])->all();
    }

    private function values(?object $row = null): array
    {
        $values = ['transactions' => (int) ($row->transactions ?? 0), 'repayment_count' => (int) ($row->repayment_count ?? 0), 'reversal_count' => (int) ($row->reversal_count ?? 0)];
        foreach (['units_sold', 'gross_sales_centavos', 'discounts_centavos', 'net_sales_centavos',
            'cash_sales_centavos', 'credit_sales_centavos', 'repayments_centavos', 'cash_collected_centavos',
            'reversed_sales_centavos', 'cash_refunds_centavos', 'net_after_reversals_centavos', 'net_cash_collected_centavos'] as $field) {
            $values[$field] = (string) ($row->$field ?? '0');
        }

        return $values;
    }
}
