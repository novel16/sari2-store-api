<?php

use App\Exceptions\CheckoutConflict;
use App\Services\Cash\CashTotals;

it('keeps exact integer cash totals through the signed platform limits', function () {
    expect(CashTotals::integer((string) PHP_INT_MAX))->toBe(PHP_INT_MAX)
        ->and(CashTotals::add(PHP_INT_MAX - 1, 1))->toBe(PHP_INT_MAX)
        ->and(CashTotals::subtract(PHP_INT_MIN + 1, 1))->toBe(PHP_INT_MIN)
        ->and(CashTotals::subtract(0, 100))->toBe(-100)
        ->and(CashTotals::subtract(5000, -100))->toBe(5100);
});

it('refuses inexact or out-of-range aggregated cash amounts', function (mixed $amount) {
    try {
        CashTotals::integer($amount);
        test()->fail('An invalid aggregate must not be converted into an integer.');
    } catch (CheckoutConflict $exception) {
        expect($exception->reason)->toBe('cash_total_overflow');
    }
})->with(['above signed maximum' => ['9223372036854775808'],
    'negative aggregate' => ['-1'], 'float aggregate' => [1.5], 'scientific notation' => ['1e3']]);

it('rejects signed arithmetic overflow instead of rounding cash', function (string $operation, int $left, int $right) {
    try {
        CashTotals::$operation($left, $right);
        test()->fail('An overflowing calculation must not return an amount.');
    } catch (CheckoutConflict $exception) {
        expect($exception->reason)->toBe('cash_total_overflow');
    }
})->with([
    ['add', PHP_INT_MAX, 1], ['add', PHP_INT_MIN, -1],
    ['subtract', PHP_INT_MIN, 1], ['subtract', PHP_INT_MAX, -1],
    ['subtract', 0, PHP_INT_MIN],
]);
