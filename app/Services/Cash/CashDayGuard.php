<?php

namespace App\Services\Cash;

use App\Exceptions\CheckoutConflict;
use App\Models\CashDay;
use App\Models\Store;
use Carbon\CarbonImmutable;

class CashDayGuard
{
    public const TIMEZONE = 'Asia/Manila';

    // Call once after acquiring the store lock, and persist this same instant on
    // the cash event. Requests crossing midnight then remain on the checked day.
    public static function capture(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    public static function businessDate(CarbonImmutable $at): string
    {
        return $at->setTimezone(self::TIMEZONE)->toDateString();
    }

    public static function ensureCashAllowed(Store $store, CarbonImmutable $at): void
    {
        if ($store->cashDays()->where('business_date', self::businessDate($at))->where('status', 'closed')->exists()) {
            throw new CheckoutConflict('cash_day_closed', 'Today has a final cash closing. Record cash transactions on the next business day.');
        }
    }

    public static function ensureOpen(CashDay $day): void
    {
        if ($day->status !== 'open') {
            throw new CheckoutConflict('cash_day_closed', 'This cash day is finally closed and cannot be changed.');
        }
    }

    public static function ensureToday(CashDay $day, CarbonImmutable $at): void
    {
        self::ensureOpen($day);
        if ($day->business_date->toDateString() !== self::businessDate($at)) {
            throw new CheckoutConflict('cash_day_not_today', 'Cash in and cash out can only be recorded for today. Close this earlier day separately.');
        }
    }
}
