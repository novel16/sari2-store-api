<?php

namespace App\Services\Dashboard;

use App\Http\Resources\SaleResource;
use App\Models\Store;
use App\Services\Reports\StoreActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardSummary
{
    public const TIMEZONE = StoreActivity::TIMEZONE;

    public function __construct(private readonly StoreActivity $activity) {}

    public function forStore(Store $store, string $period): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $today = $now->startOfDay();
        [$from, $to] = match ($period) {
            'last_week' => [$today->startOfWeek(CarbonInterface::MONDAY)->subWeek(), $today->startOfWeek(CarbonInterface::MONDAY)->subDay()],
            'this_month' => [$today->startOfMonth(), $today],
            default => [$today->startOfWeek(CarbonInterface::MONDAY), $today],
        };

        // On MySQL's default REPEATABLE READ, all panels share one read snapshot.
        // No store/stock locks are taken by this read-only dashboard.
        return DB::transaction(function () use ($store, $period, $now, $today, $from, $to) {
            $stock = $this->stock($store);
            $stockTotals = (clone $stock)->selectRaw('COUNT(*) AS active_products,
                COALESCE(SUM(CASE WHEN COALESCE(i.quantity_on_hand, 0) > p.low_stock_threshold THEN 1 ELSE 0 END), 0) AS in_stock,
                COALESCE(SUM(CASE WHEN i.quantity_on_hand > 0 AND i.quantity_on_hand <= p.low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock,
                COALESCE(SUM(CASE WHEN COALESCE(i.quantity_on_hand, 0) = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock')->first();
            $credit = DB::table('customers')->where('store_id', $store->id)
                ->selectRaw('COALESCE(SUM(balance_centavos), 0) AS outstanding_centavos,
                    COALESCE(SUM(CASE WHEN balance_centavos > 0 THEN 1 ELSE 0 END), 0) AS customers_with_balance,
                    COALESCE(SUM(CASE WHEN balance_centavos > 0 AND status = ? THEN 1 ELSE 0 END), 0) AS archived_with_balance', ['archived'])->first();

            return [
                'timezone' => self::TIMEZONE,
                'business_date' => $today->toDateString(),
                'generated_at' => $now->utc()->toIso8601String(),
                'today' => $this->today($store, $today),
                'credit' => [
                    'outstanding_centavos' => (string) $credit->outstanding_centavos,
                    'customers_with_balance' => (int) $credit->customers_with_balance,
                    'archived_with_balance' => (int) $credit->archived_with_balance,
                ],
                'stock' => [
                    'active_products' => (int) $stockTotals->active_products,
                    'in_stock' => (int) $stockTotals->in_stock,
                    'low_stock' => (int) $stockTotals->low_stock,
                    'out_of_stock' => (int) $stockTotals->out_of_stock,
                    'alerts' => (clone $stock)->whereRaw('COALESCE(i.quantity_on_hand, 0) <= p.low_stock_threshold')
                        ->select(['p.id', 'p.name', 'p.unit', 'p.emoji', 'p.color', 'p.low_stock_threshold'])
                        ->selectRaw('COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand')
                        ->orderBy('quantity_on_hand')->orderBy('p.name')->orderBy('p.id')->limit(5)->get()
                        ->map(fn ($product) => [
                            'id' => (int) $product->id, 'name' => $product->name, 'unit' => $product->unit,
                            'emoji' => $product->emoji, 'color' => $product->color,
                            'quantity_on_hand' => (int) $product->quantity_on_hand,
                            'low_stock_threshold' => (int) $product->low_stock_threshold,
                        ])->all(),
                ],
                'chart' => [
                    'period' => $period, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
                    'days' => $this->dailySales($store, $from, $to),
                ],
                'top_products' => $this->topProducts($store, $from, $to),
                'recent_sales' => SaleResource::collection($store->sales()->where('status', 'completed')
                    ->with('payment')->orderByDesc('completed_at')->orderByDesc('id')->limit(5)->get())->resolve(),
            ];
        });
    }

    private function today(Store $store, CarbonImmutable $day): array
    {
        $summary = $this->activity->summary($store, $day, $day);
        unset($summary['units_sold'], $summary['repayment_count']);

        return $summary;
    }

    private function stock(Store $store): Builder
    {
        return DB::table('products as p')->where('p.store_id', $store->id)->where('p.status', 'active')
            ->leftJoin('inventories as i', fn ($join) => $join->on('i.product_id', '=', 'p.id')->on('i.store_id', '=', 'p.store_id'));
    }

    private function dailySales(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_map(fn ($day) => array_intersect_key($day, array_flip(['date', 'net_sales_centavos', 'transactions'])),
            $this->activity->days($store, $from, $to));
    }

    private function topProducts(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_map(fn ($product) => array_intersect_key($product, array_flip(['product_id', 'name', 'unit', 'units_sold'])),
            $this->activity->topProducts($store, $from, $to));
    }
}
