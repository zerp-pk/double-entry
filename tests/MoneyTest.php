<?php

namespace Zerp\DoubleEntry\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Zerp\DoubleEntry\Support\Money;

/**
 * The reports used to add money up as PHP floats, which does not preserve the exact
 * cent amounts the DECIMAL columns hold, and then compared the drifted totals against
 * a 0.01 tolerance. See zerp-pk/double-entry#2.
 *
 * No database and no framework: this is arithmetic.
 */
class MoneyTest extends BaseTestCase
{
    public function test_it_parses_decimal_strings_the_driver_returns(): void
    {
        $this->assertSame(123456, Money::toCents('1234.56'));
        $this->assertSame(-123456, Money::toCents('-1234.56'));
        $this->assertSame(1, Money::toCents('0.01'));
        $this->assertSame(-1, Money::toCents('-0.01'));
        $this->assertSame(0, Money::toCents('0.00'));
        $this->assertSame(150000, Money::toCents('1500'));
        $this->assertSame(0, Money::toCents(null));
        $this->assertSame(0, Money::toCents(''));
    }

    public function test_a_float_is_rounded_to_the_cent_rather_than_truncated(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in binary floating point. Truncating that
        // would give 30 cents by luck, but 2.675 truncates to 267 and must be 268.
        $this->assertSame(30, Money::toCents(0.1 + 0.2));
        $this->assertSame(268, Money::toCents(2.675));
        $this->assertSame(-268, Money::toCents(-2.675));
    }

    public function test_summing_cents_stays_exact_where_floats_drift(): void
    {
        // The float version of this loop does not land on 0.03 exactly, and over a
        // few hundred accounts the error grows past the old 0.01 tolerance.
        $cents = 0;
        $float = 0.0;
        for ($i = 0; $i < 1000; $i++) {
            $cents += Money::toCents('0.01');
            $float += 0.01;
        }

        $this->assertSame(1000, $cents);
        $this->assertSame(10.0, Money::toAmount($cents));

        // Guard the premise: if this ever becomes exact, the bug is gone from PHP
        // itself and this whole class could be reconsidered.
        $this->assertNotSame(10.0, $float);
    }

    public function test_a_balanced_pair_compares_equal_without_a_tolerance(): void
    {
        $debit = 0;
        $credit = 0;
        foreach (['0.01', '0.02', '1234.56', '0.07'] as $amount) {
            $debit += Money::toCents($amount);
        }
        foreach (['1234.56', '0.10'] as $amount) {
            $credit += Money::toCents($amount);
        }

        $this->assertSame($debit, $credit);
    }

    public function test_a_one_cent_imbalance_is_not_hidden(): void
    {
        // The old check was abs($debit - $credit) < 0.01, so a genuine one cent
        // difference reported the ledger as balanced.
        $debit = Money::toCents('100.00');
        $credit = Money::toCents('99.99');

        $this->assertNotSame($debit, $credit);
        $this->assertSame(1, $debit - $credit);
    }

    public function test_round_trip_through_an_amount_keeps_the_value(): void
    {
        foreach (['0.01', '0.99', '1234.56', '-0.01', '999999.99'] as $amount) {
            $cents = Money::toCents($amount);
            $this->assertSame($cents, Money::toCents(Money::toAmount($cents)), $amount);
        }
    }
}
