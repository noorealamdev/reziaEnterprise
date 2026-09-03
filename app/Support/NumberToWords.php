<?php

namespace App\Support;

class NumberToWords
{
    /**
     * Spell out a Taka amount using Bangladeshi/Indian numbering
     * (Crore = 10,000,000, Lakh = 100,000) rather than the Western
     * million/billion grouping — matches how amounts are written on the
     * reference paper invoices (e.g. "Three Lakh Eighteen Thousand Five
     * Hundred Fifty Two"). Paisa is dropped; invoice totals are always
     * whole Taka in practice, so the amount is rounded to the nearest Taka.
     */
    public static function taka(float $amount): string
    {
        $amount = (int) round($amount);

        if ($amount === 0) {
            return 'Zero';
        }

        $negative = $amount < 0;
        $amount = abs($amount);

        $crore = intdiv($amount, 1_00_00_000);
        $amount %= 1_00_00_000;

        $lakh = intdiv($amount, 1_00_000);
        $amount %= 1_00_000;

        $thousand = intdiv($amount, 1_000);
        $amount %= 1_000;

        $hundred = intdiv($amount, 100);
        $remainder = $amount % 100;

        $parts = [];

        if ($crore > 0) {
            $parts[] = self::underThousand($crore).' Crore';
        }

        if ($lakh > 0) {
            $parts[] = self::underThousand($lakh).' Lakh';
        }

        if ($thousand > 0) {
            $parts[] = self::underThousand($thousand).' Thousand';
        }

        if ($hundred > 0) {
            $parts[] = self::ones($hundred).' Hundred';
        }

        if ($remainder > 0) {
            $parts[] = self::underHundred($remainder);
        }

        return ($negative ? 'Minus ' : '').implode(' ', $parts);
    }

    /**
     * A value from 1-999 (used for the Crore/Lakh/Thousand groups, which
     * can themselves run up to three digits, e.g. "Twelve Crore").
     */
    private static function underThousand(int $n): string
    {
        $hundred = intdiv($n, 100);
        $remainder = $n % 100;

        $parts = [];

        if ($hundred > 0) {
            $parts[] = self::ones($hundred).' Hundred';
        }

        if ($remainder > 0) {
            $parts[] = self::underHundred($remainder);
        }

        return implode(' ', $parts);
    }

    /**
     * A value from 1-99.
     */
    private static function underHundred(int $n): string
    {
        if ($n < 20) {
            return self::ones($n);
        }

        $tens = [
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
        ];

        $word = $tens[intdiv($n, 10)];
        $remainder = $n % 10;

        return $remainder > 0 ? $word.' '.self::ones($remainder) : $word;
    }

    /**
     * A value from 1-19.
     */
    private static function ones(int $n): string
    {
        $words = [
            1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
            6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
            11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
            16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        ];

        return $words[$n];
    }
}
