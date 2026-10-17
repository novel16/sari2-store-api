<?php

namespace App\Services\Reports;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SalesReport
{
    public function __construct(private readonly StoreActivity $activity) {}

    public function forStore(Store $store, string $dateFrom, string $dateTo): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, StoreActivity::TIMEZONE);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, StoreActivity::TIMEZONE);
        $now = CarbonImmutable::now('UTC');

        return DB::transaction(fn () => [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'timezone' => StoreActivity::TIMEZONE, 'date_from' => $dateFrom, 'date_to' => $dateTo,
            'generated_at' => $now->toIso8601String(),
            'summary' => $this->activity->summary($store, $from, $to),
            'days' => $this->activity->days($store, $from, $to),
            'top_products_limit' => 10,
            'top_products' => $this->activity->topProducts($store, $from, $to, 10),
        ]);
    }
}
