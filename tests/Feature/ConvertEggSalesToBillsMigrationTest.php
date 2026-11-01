<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function convertEggSalesMigration(): object
{
    return require database_path('migrations/2026_10_31_170000_convert_egg_sales_to_monthly_bills.php');
}

/**
 * Puts the egg tables back to how they looked before the conversion.
 */
function switchToPreBillingEggSchema(): void
{
    foreach (['egg_sales', 'egg_invoice_payments', 'egg_invoices', 'egg_sales_archive', 'egg_buyers'] as $table) {
        Schema::dropIfExists($table);
    }

    (require database_path('migrations/2026_10_31_151855_restore_egg_sales_system.php'))->up();

    DB::table('egg_buyers')->insert([
        ['id' => 3, 'name' => 'Mr.Akter'],
        ['id' => 4, 'name' => 'Mr.Shafin'],
    ]);
    DB::table('egg_in_charges')->insert(['id' => 1, 'name' => 'Mr.Akter']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertPreBillingSale(int $id, array $attributes = []): void
{
    DB::table('egg_sales')->insert([
        'id' => $id,
        'sale_date' => '2026-10-04',
        'quantity' => 100,
        'sale_rate' => 10,
        'sale_amount' => 1000,
        'egg_buyer_id' => 4,
        'payment_status' => 'due',
        'remarks' => null,
        ...$attributes,
    ]);
}

test('cash sales become a paid bill and due sales stay unbilled', function () {
    switchToPreBillingEggSchema();
    insertPreBillingSale(16, ['sale_date' => '2026-09-01', 'sale_amount' => 420, 'egg_buyer_id' => 3, 'payment_status' => 'cash']);
    insertPreBillingSale(17, ['sale_date' => '2026-09-27', 'sale_amount' => 210, 'egg_buyer_id' => 3, 'payment_status' => 'cash']);
    insertPreBillingSale(36, ['sale_date' => '2026-10-02', 'sale_amount' => 180, 'egg_buyer_id' => 3]);

    convertEggSalesMigration()->up();

    $bill = DB::table('egg_invoices')->sole();
    expect($bill->status)->toBe('paid');
    expect($bill->invoice_number)->toBe('RE/EGG/B3/092026');
    expect(substr($bill->period_end, 0, 10))->toBe('2026-09-30');
    expect(substr($bill->paid_at, 0, 10))->toBe('2026-09-27');

    $payment = DB::table('egg_invoice_payments')->sole();
    expect((float) $payment->amount)->toBe(630.0);
    expect($payment->egg_invoice_id)->toBe($bill->id);

    expect(DB::table('egg_sales')->whereIn('id', [16, 17])->pluck('egg_invoice_id')->unique()->all())->toBe([$bill->id]);
    expect(DB::table('egg_sales')->where('id', 36)->value('egg_invoice_id'))->toBeNull();
});

test('every old detail is archived before its column is removed', function () {
    switchToPreBillingEggSchema();
    insertPreBillingSale(39, ['egg_in_charge_id' => 1, 'remarks' => 'Keep me']);

    convertEggSalesMigration()->up();

    $archived = DB::table('egg_sales_archive')->sole();
    expect($archived->sale_id)->toBe(39);
    expect($archived->payment_status)->toBe('due');
    expect($archived->in_charge_name)->toBe('Mr.Akter');
    expect($archived->buyer_name)->toBe('Mr.Shafin');
    expect($archived->remarks)->toBe('Keep me');

    expect(Schema::hasColumn('egg_sales', 'payment_status'))->toBeFalse();
    expect(Schema::hasColumn('egg_sales', 'egg_in_charge_id'))->toBeFalse();
    expect(Schema::hasTable('egg_in_charges'))->toBeFalse();
    expect(Schema::hasTable('egg_buyer_payments'))->toBeFalse();
    expect(DB::table('egg_sales')->where('id', 39)->value('remarks'))->toBe('Keep me');
});

test('sale 35 is removed as the confirmed duplicate of sale 45 but stays in the archive', function () {
    switchToPreBillingEggSchema();
    insertPreBillingSale(35, ['quantity' => 260, 'sale_amount' => 3172, 'egg_buyer_id' => null]);
    insertPreBillingSale(45, ['quantity' => 260, 'sale_amount' => 3172]);

    convertEggSalesMigration()->up();

    expect(DB::table('egg_sales')->pluck('id')->all())->toBe([45]);
    expect((bool) DB::table('egg_sales_archive')->where('sale_id', 35)->value('removed_as_duplicate'))->toBeTrue();
    expect((bool) DB::table('egg_sales_archive')->where('sale_id', 45)->value('removed_as_duplicate'))->toBeFalse();
});

test('any other sale without a buyer stops the conversion with nothing changed', function (array $sale35, array $sale45) {
    switchToPreBillingEggSchema();
    insertPreBillingSale(35, $sale35);
    insertPreBillingSale(45, $sale45);

    expect(fn () => convertEggSalesMigration()->up())->toThrow(RuntimeException::class, 'nothing was changed');

    expect(DB::table('egg_sales')->count())->toBe(2);
    expect(Schema::hasColumn('egg_sales', 'payment_status'))->toBeTrue();
    expect(Schema::hasTable('egg_invoices'))->toBeFalse();
    expect(Schema::hasTable('egg_sales_archive'))->toBeFalse();
})->with([
    'different amount' => [['quantity' => 260, 'sale_amount' => 9999, 'egg_buyer_id' => null], ['quantity' => 260, 'sale_amount' => 3172]],
    'original changed' => [['quantity' => 260, 'sale_amount' => 3172, 'egg_buyer_id' => null], ['quantity' => 999, 'sale_amount' => 3172]],
    'different date' => [['sale_date' => '2026-10-05', 'quantity' => 260, 'sale_amount' => 3172, 'egg_buyer_id' => null], ['sale_date' => '2026-10-05', 'quantity' => 260, 'sale_amount' => 3172]],
]);

test('a sale with an unexpected status stops the conversion with nothing changed', function () {
    switchToPreBillingEggSchema();
    insertPreBillingSale(50, ['payment_status' => 'paid']);

    expect(fn () => convertEggSalesMigration()->up())->toThrow(RuntimeException::class, 'unexpected payment status');

    expect(Schema::hasColumn('egg_sales', 'payment_status'))->toBeTrue();
    expect(Schema::hasTable('egg_invoices'))->toBeFalse();
});

test('existing buyer payments stop the conversion with nothing changed', function () {
    switchToPreBillingEggSchema();
    DB::table('egg_buyer_payments')->insert(['egg_buyer_id' => 3, 'amount' => 50, 'paid_on' => '2026-10-01']);

    expect(fn () => convertEggSalesMigration()->up())->toThrow(RuntimeException::class, 'nothing was changed');

    expect(DB::table('egg_buyer_payments')->count())->toBe(1);
    expect(Schema::hasTable('egg_invoices'))->toBeFalse();
});

test('a buyer with sales can no longer be removed at database level', function () {
    switchToPreBillingEggSchema();
    insertPreBillingSale(60);

    convertEggSalesMigration()->up();

    expect(fn () => DB::table('egg_buyers')->where('id', 4)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('egg_sales')->insert([
        'sale_date' => '2026-10-01', 'quantity' => 1, 'sale_rate' => 1, 'sale_amount' => 1, 'egg_buyer_id' => null,
    ]))->toThrow(QueryException::class);
});
