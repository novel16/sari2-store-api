<?php

namespace App\Services\Cash;

use App\Exceptions\CheckoutConflict;
use App\Models\CashDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CashTotals
{
    public function forDay(CashDay $day): array
    {
        return $day->status === 'closed' ? $day->closing['totals'] : $this->activity($day)['totals'];
    }

    public function activity(CashDay $day): array
    {
        $from = CarbonImmutable::parse($day->business_date->toDateString(), CashDayGuard::TIMEZONE)->startOfDay()->utc();
        $to = $from->addDay();
        $sales = $this->aggregate($this->period('sales', $day, 'completed_at', $from, $to)
            ->where('status', 'completed')->where('payment_method', 'cash'), 'amount_paid_centavos');
        $payments = $this->aggregate($this->period('credit_ledger_entries', $day, 'created_at', $from, $to)
            ->where('entry_type', 'payment')->where('payment_method', 'cash'), 'amount_centavos');
        $refunds = $this->aggregate($this->period('sale_reversals', $day, 'created_at', $from, $to)
            ->where('cash_refund_centavos', '>', 0), 'cash_refund_centavos');
        $movements = DB::table('cash_movements')->where('store_id', $day->store_id)->where('cash_day_id', $day->id)
            ->selectRaw("COUNT(*) AS event_count, COALESCE(MAX(id),0) AS last_id,
                COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_centavos ELSE 0 END),0) AS cash_in,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_centavos ELSE 0 END),0) AS cash_out")->first();
        $cashIn = self::integer($movements->cash_in);
        $cashOut = self::integer($movements->cash_out);
        $expected = self::integer($day->opening_cash_centavos);
        foreach ([$sales['amount'], $payments['amount'], $cashIn] as $amount) {
            $expected = self::add($expected, $amount);
        }
        foreach ([$refunds['amount'], $cashOut] as $amount) {
            $expected = self::subtract($expected, $amount);
        }
        $totals = ['opening_cash_centavos' => (string) $day->opening_cash_centavos,
            'cash_sales_centavos' => (string) $sales['amount'], 'repayments_centavos' => (string) $payments['amount'],
            'cash_refunds_centavos' => (string) $refunds['amount'], 'cash_in_centavos' => (string) $cashIn,
            'cash_out_centavos' => (string) $cashOut, 'expected_cash_centavos' => (string) $expected,
            'cash_sale_count' => $sales['count'], 'repayment_count' => $payments['count'],
            'cash_refund_count' => $refunds['count'], 'movement_count' => self::integer($movements->event_count)];

        // Counts and latest IDs detect offsetting immutable events even when the
        // expected drawer total is unchanged between review and confirmation.
        return ['totals' => $totals, 'fingerprint' => hash('sha256', json_encode([
            $day->id, $totals, $sales, $payments, $refunds, self::integer($movements->last_id),
        ], JSON_THROW_ON_ERROR))];
    }

    private function period(string $table, CashDay $day, string $column, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table($table)->where('store_id', $day->store_id)
            ->where($column, '>=', $from->toDateTimeString())->where($column, '<', $to->toDateTimeString());
    }

    private function aggregate(Builder $query, string $column): array
    {
        $row = $query->selectRaw("COUNT(*) AS event_count, COALESCE(MAX(id),0) AS last_id, COALESCE(SUM({$column}),0) AS amount")->first();

        return ['amount' => self::integer($row->amount), 'count' => self::integer($row->event_count), 'last_id' => self::integer($row->last_id)];
    }

    public static function integer(mixed $value): int
    {
        $text = is_int($value) || is_string($value) ? (string) $value : '';
        $limit = (string) PHP_INT_MAX;
        if (! preg_match('/^(0|[1-9][0-9]*)$/D', $text) || strlen($text) > strlen($limit)
            || (strlen($text) === strlen($limit) && strcmp($text, $limit) > 0)) {
            self::overflow();
        }

        return (int) $text;
    }

    public static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            self::overflow();
        }

        return $left + $right;
    }

    public static function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right) || ($right < 0 && $left > PHP_INT_MAX + $right)) {
            self::overflow();
        }

        return $left - $right;
    }

    private static function overflow(): never
    {
        throw new CheckoutConflict('cash_total_overflow', 'The cash totals exceed the supported exact amount. Review the financial records before continuing.');
    }
}
