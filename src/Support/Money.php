<?php

namespace Zerp\DoubleEntry\Support;

/**
 * Money as integer minor units.
 *
 * Every money column in this module is DECIMAL(x, 2), so every amount is an exact
 * whole number of cents. Reading them into PHP floats and adding those up does not
 * preserve that: 0.1 + 0.2 is not 0.3 in binary floating point, and the error grows
 * with the number of accounts. The reports then compared the drifted totals with a
 * 0.01 tolerance, which both hid real imbalances below a cent and reported balanced
 * ledgers as unbalanced once the drift passed the tolerance.
 *
 * Totals are summed as integers here, so they are exact and can be compared with ==.
 * Floats appear only at the boundary, where the amount is handed to the frontend.
 */
final class Money
{
    /**
     * Parse a DECIMAL column into cents without letting a float hold the value.
     *
     * The driver hands DECIMAL back as a string ("-1234.56"), so it is parsed as
     * text. Floats are accepted for callers that already have one, and are rounded
     * to the cent on the way in.
     */
    public static function toCents(int|float|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        // A float has already lost exactness, so round it to the cent rather than
        // truncating: 0.1 + 0.2 arrives as 0.30000000000000004 and must land on 30.
        // round() is used rather than sprintf('%.2F') because it corrects for the
        // representation, so a literal 2.675 (really 2.67499...) gives 2.68 as a
        // person would expect instead of 2.67. Amounts that come from the database
        // are strings and take the exact path below; this branch is a convenience.
        $text = is_float($value) ? sprintf('%.2F', round($value, 2)) : trim($value);

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;

        // Keep two digits, rounding half up on the third if the caller passed more.
        $fraction = str_pad($fraction, 3, '0');
        $cents = (int) $whole * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /**
     * Back to a decimal amount, for JSON handed to the frontend.
     */
    public static function toAmount(int $cents): float
    {
        return $cents / 100;
    }
}
