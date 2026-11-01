<?php

use App\Support\NumberToWords;

test('zero is spelled out as Zero', function () {
    expect(NumberToWords::taka(0))->toBe('Zero');
});

test('small amounts under a hundred', function () {
    expect(NumberToWords::taka(1))->toBe('One');
    expect(NumberToWords::taka(15))->toBe('Fifteen');
    expect(NumberToWords::taka(52))->toBe('Fifty Two');
    expect(NumberToWords::taka(100))->toBe('One Hundred');
});

test('thousands and lakhs', function () {
    expect(NumberToWords::taka(1500))->toBe('One Thousand Five Hundred');
    expect(NumberToWords::taka(100000))->toBe('One Lakh');
});

test('amounts matching the reference paper invoices use Bangladeshi lakh/crore grouping, not million/billion', function () {
    expect(NumberToWords::taka(318552))->toBe('Three Lakh Eighteen Thousand Five Hundred Fifty Two');
    expect(NumberToWords::taka(1098000))->toBe('Ten Lakh Ninety Eight Thousand');
    expect(NumberToWords::taka(1207800))->toBe('Twelve Lakh Seven Thousand Eight Hundred');
    expect(NumberToWords::taka(1058730))->toBe('Ten Lakh Fifty Eight Thousand Seven Hundred Thirty');
    expect(NumberToWords::taka(560000))->toBe('Five Lakh Sixty Thousand');
    expect(NumberToWords::taka(350000))->toBe('Three Lakh Fifty Thousand');
});

test('crore-scale amounts', function () {
    expect(NumberToWords::taka(12_345_678))->toBe('One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight');
});

test('fractional amounts round to the nearest taka', function () {
    expect(NumberToWords::taka(999.60))->toBe('One Thousand');
    expect(NumberToWords::taka(999.40))->toBe('Nine Hundred Ninety Nine');
});

test('amounts can be spelled out exactly with their paisa', function () {
    expect(NumberToWords::takaWithPaisa(4157.50))->toBe('Four Thousand One Hundred Fifty Seven Taka And Fifty Paisa');
    expect(NumberToWords::takaWithPaisa(1961.25))->toBe('One Thousand Nine Hundred Sixty One Taka And Twenty Five Paisa');
    expect(NumberToWords::takaWithPaisa(999.99))->toBe('Nine Hundred Ninety Nine Taka And Ninety Nine Paisa');
    expect(NumberToWords::takaWithPaisa(2000))->toBe('Two Thousand Taka');
    expect(NumberToWords::takaWithPaisa(0))->toBe('Zero Taka');
});
