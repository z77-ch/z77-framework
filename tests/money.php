<?php

/**
 * Money harness (CLI) — integer money, ADR-042.
 *
 * What is load-bearing here:
 *
 *   - NO FLOAT gets in: every numeric entry point refuses a float with a
 *     TypeError, even though the kernel runs without strict_types;
 *   - the wdv float bugs as cases: 0.1 + 0.2, a strict compare of a computed
 *     amount, VAT not rounded, a rounding difference smeared onto the last
 *     part — each must come out exact here;
 *   - allocate() never loses or invents a Rappen, for positive and negative
 *     amounts;
 *   - rounding is half away from zero, also for 0.05 steps and negatives.
 *
 * Run: php tests/money.php
 * Pure value object — the harness requires the one file.
 */

require __DIR__ . '/../packages/kernel/shared/src/Money/Money.php';

use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

function throws(callable $fn, string $class): bool
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

echo "Construction and formatting\n";
check('fromDecimal 12.5 = 1250 minor', chf('12.5')->minor === 1250);
check('fromDecimal -0.05 = -5 minor', chf('-0.05')->minor === -5);
check('fromDecimal 1200 = 120000 minor', chf('1200')->minor === 120000);
check('toDecimal pads', Money::of(5, 'CHF')->toDecimal() === '0.05');
check('toDecimal negative', Money::of(-1250, 'CHF')->toDecimal() === '-12.50');
check('round trip DECIMAL string', chf('-1234567.89')->toDecimal() === '-1234567.89');
check('three decimals refused', throws(fn() => chf('12.345'), \InvalidArgumentException::class));
check('garbage refused', throws(fn() => chf('12,50'), \InvalidArgumentException::class));
check('lower-case currency refused', throws(fn() => Money::of(1, 'chf'), \InvalidArgumentException::class));

echo "No float gets in\n";
check('of(12.5) is TypeError', throws(fn() => Money::of(12.5, 'CHF'), \TypeError::class));
check('of(12.0) is TypeError too', throws(fn() => Money::of(12.0, 'CHF'), \TypeError::class));
check('multiply(1.5) is TypeError', throws(fn() => chf('1')->multiply(1.5), \TypeError::class));
check('multiplyByRatio(float) is TypeError', throws(fn() => chf('1')->multiplyByRatio(8.1, 100), \TypeError::class));
check('allocate([0.5, 0.5]) is TypeError', throws(fn() => chf('1')->allocate([0.5, 0.5]), \TypeError::class));
check('roundTo(0.05) is TypeError', throws(fn() => chf('1')->roundTo(0.05), \TypeError::class));

echo "wdv float bugs\n";
check('0.10 + 0.20 equals 0.30 exactly', chf('0.10')->add(chf('0.20'))->equals(chf('0.30')));
check('strict compare of a computed amount holds', chf('19.99')->multiply(3)->equals(chf('59.97')));
check('8.1 % of 100.00 = 8.10', chf('100.00')->multiplyByRatio(810, 10000)->equals(chf('8.10')));
check('8.1 % of 12.35 = 1.00 (1.00035 rounded)', chf('12.35')->multiplyByRatio(810, 10000)->equals(chf('1.00')));
check('7.7 % of 0.65 = 0.05 (0.05005 rounded)', chf('0.65')->multiplyByRatio(770, 10000)->equals(chf('0.05')));
check('tax in 108.10 gross at 8.1 % = 8.10', chf('108.10')->multiplyByRatio(810, 10810)->equals(chf('8.10')));
$parts = chf('100.00')->allocate([1, 1, 1]);
check('100.00 / 3 = 33.34 + 33.33 + 33.33', array_map(fn($m) => $m->minor, $parts) === [3334, 3333, 3333]);
check('…and the parts add up to 100.00', array_reduce($parts, fn($c, $m) => $c->add($m), Money::zero('CHF'))->equals(chf('100.00')));

echo "Multiplication\n";
check('multiply by "1.5"', chf('10.01')->multiply('1.5')->equals(chf('15.02'))); // 15.015 → 15.02
check('multiply by "-0.5"', chf('0.03')->multiply('-0.5')->equals(chf('-0.02'))); // −0.015 → −0.02
check('multiply by int', chf('2.50')->multiply(-4)->equals(chf('-10.00')));
check('factor with ten decimals refused', throws(fn() => chf('1')->multiply('0.0000000001'), \InvalidArgumentException::class));
check('denominator 0 refused', throws(fn() => chf('1')->multiplyByRatio(1, 0), \DivisionByZeroError::class));
check('overflow refused, not a float', throws(fn() => Money::of(PHP_INT_MAX, 'CHF')->multiply(2), \OverflowException::class));
check('overflow in add refused', throws(fn() => Money::of(PHP_INT_MAX, 'CHF')->add(Money::of(1, 'CHF')), \OverflowException::class));

echo "Allocation\n";
$neg = chf('-100.00')->allocate([1, 1, 1]);
check('negative: -33.34, -33.33, -33.33', array_map(fn($m) => $m->minor, $neg) === [-3334, -3333, -3333]);
$vat = chf('10.00')->allocate([7700, 250, 50]); // discount split by the tax summary's bases
check('by base ratios: sum preserved', array_sum(array_map(fn($m) => $m->minor, $vat)) === 1000);
check('by base ratios: 9.63 / 0.31 / 0.06', array_map(fn($m) => $m->minor, $vat) === [963, 31, 6]);
check('zero ratio gets zero', array_map(fn($m) => $m->minor, chf('1.00')->allocate([0, 1]))  === [0, 100]);
check('all-zero ratios refused', throws(fn() => chf('1')->allocate([0, 0]), \InvalidArgumentException::class));
check('negative ratio refused', throws(fn() => chf('1')->allocate([2, -1]), \InvalidArgumentException::class));
check('0.01 into three: one part gets it', array_map(fn($m) => $m->minor, chf('0.01')->allocate([1, 1, 1])) === [1, 0, 0]);

echo "Rounding to 0.05\n";
foreach (['12.32' => '12.30', '12.33' => '12.35', '12.37' => '12.35', '12.38' => '12.40', '12.35' => '12.35', '-12.33' => '-12.35', '-12.32' => '-12.30'] as $in => $out) {
    check("{$in} → {$out}", chf((string) $in)->roundTo(5)->equals(chf($out)));
}
check('exact half rounds away from zero: 0.15 → 0.20 at step 10', Money::of(15, 'CHF')->roundTo(10)->minor === 20);
check('…and -0.15 → -0.20', Money::of(-15, 'CHF')->roundTo(10)->minor === -20);
check('step 0 refused', throws(fn() => chf('1')->roundTo(0), \InvalidArgumentException::class));

echo "Comparison and currency\n";
check('greaterThan', chf('1.01')->greaterThan(chf('1.00')));
check('lessThan negative', chf('-1.00')->lessThan(chf('0')));
check('isZero / isNegative / isPositive', chf('0')->isZero() && chf('-0.01')->isNegative() && chf('0.01')->isPositive());
check('equals is false across currencies', !chf('1')->equals(Money::fromDecimal('1', 'EUR')));
check('add across currencies refused', throws(fn() => chf('1')->add(Money::fromDecimal('1', 'EUR')), \InvalidArgumentException::class));
check('__toString', (string) chf('-3.5') === '-3.50 CHF');
$original = chf('1');
$sum      = $original->add(chf('0.50'));
check('immutable: add returns a new object and leaves the original', $sum !== $original && $original->minor === 100);

echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
exit($fail === 0 ? 0 : 1);
