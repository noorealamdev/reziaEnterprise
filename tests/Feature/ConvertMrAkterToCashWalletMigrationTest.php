<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function akterWalletMigration(): object
{
    return require database_path('migrations/2026_11_01_124825_convert_mr_akter_to_cash_wallet.php');
}

/**
 * Puts the egg tables back to how they looked before the wallet conversion,
 * with Mr.Akter and another buyer each holding a bill.
 */
function switchToPreWalletEggSchema(): void
{
    Schema::dropIfExists('egg_cash_handovers');
    Schema::dropIfExists('egg_wallet_conversion_archive');
    Schema::table('egg_buyers', fn ($table) => $table->dropColumn('uses_cash_wallet'));

    DB::table('egg_buyers')->insert([
        ['id' => 3, 'name' => 'Mr.Akter'],
        ['id' => 4, 'name' => 'Mr.Shafin'],
    ]);
    foreach ([[1, 3, 'RE/EGG/B3/092026'], [2, 4, 'RE/EGG/B4/092026']] as [$id, $buyerId, $number]) {
        DB::table('egg_invoices')->insert([
            'id' => $id,
            'egg_buyer_id' => $buyerId,
            'invoice_number' => $number,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => 'paid',
        ]);
        DB::table('egg_invoice_payments')->insert([
            'egg_invoice_id' => $id,
            'amount' => 630,
            'paid_on' => '2026-09-27',
            'description' => 'Paid in cash when these sales were recorded.',
        ]);
    }

    foreach ([[16, 3, 1, 420], [17, 3, 1, 210], [36, 3, null, 180], [40, 4, 2, 630], [41, 4, null, 99]] as [$id, $buyerId, $invoiceId, $amount]) {
        DB::table('egg_sales')->insert([
            'id' => $id,
            'sale_date' => '2026-09-10',
            'quantity' => 10,
            'sale_rate' => 10,
            'sale_amount' => $amount,
            'egg_buyer_id' => $buyerId,
            'egg_invoice_id' => $invoiceId,
        ]);
    }
}

test('his paid bill becomes a handover on the payment date and his balance is what is left', function () {
    switchToPreWalletEggSchema();

    akterWalletMigration()->up();

    expect((bool) DB::table('egg_buyers')->where('id', 3)->value('uses_cash_wallet'))->toBeTrue();

    $handover = DB::table('egg_cash_handovers')->sole();
    expect($handover->egg_buyer_id)->toBe(3);
    expect(substr($handover->handed_on, 0, 10))->toBe('2026-09-27');
    expect((float) $handover->amount)->toBe(630.0);
    expect($handover->note)->toContain('RE/EGG/B3/092026');

    expect(DB::table('egg_sales')->where('egg_buyer_id', 3)->whereNotNull('egg_invoice_id')->count())->toBe(0);
    expect((float) DB::table('egg_sales')->where('egg_buyer_id', 3)->sum('sale_amount'))->toBe(810.0);
});

test('the removed bill is fully archived first', function () {
    switchToPreWalletEggSchema();

    akterWalletMigration()->up();

    $archive = DB::table('egg_wallet_conversion_archive')->sole();
    expect($archive->invoice_number)->toBe('RE/EGG/B3/092026');
    expect(json_decode($archive->sale_ids))->toBe([16, 17]);
    expect((float) json_decode($archive->payments)[0]->amount)->toBe(630.0);
    expect(json_decode($archive->invoice)->status)->toBe('paid');
    expect(DB::table('egg_invoices')->where('egg_buyer_id', 3)->exists())->toBeFalse();
    expect(DB::table('egg_invoice_payments')->where('egg_invoice_id', 1)->exists())->toBeFalse();
});

test('other buyers keep their bills, payments and monthly billing', function () {
    switchToPreWalletEggSchema();

    akterWalletMigration()->up();

    expect((bool) DB::table('egg_buyers')->where('id', 4)->value('uses_cash_wallet'))->toBeFalse();
    expect(DB::table('egg_invoices')->where('id', 2)->value('status'))->toBe('paid');
    expect(DB::table('egg_invoice_payments')->where('egg_invoice_id', 2)->count())->toBe(1);
    expect(DB::table('egg_sales')->where('id', 40)->value('egg_invoice_id'))->toBe(2);
});

test('a check payment keeps its check details on the handover', function () {
    switchToPreWalletEggSchema();
    DB::table('egg_invoice_payments')->where('egg_invoice_id', 1)->update(['type' => 'check', 'check_number' => '0451', 'bank_name' => 'City Bank']);

    akterWalletMigration()->up();

    expect(DB::table('egg_cash_handovers')->value('note'))->toContain('Check 0451, City Bank');
});

test('payments larger than his sales stop the conversion with nothing changed', function () {
    switchToPreWalletEggSchema();
    DB::table('egg_invoice_payments')->where('egg_invoice_id', 1)->update(['amount' => 5000]);

    expect(fn () => akterWalletMigration()->up())->toThrow(RuntimeException::class, 'nothing was changed');

    expect(Schema::hasTable('egg_cash_handovers'))->toBeFalse();
    expect(Schema::hasColumn('egg_buyers', 'uses_cash_wallet'))->toBeFalse();
    expect(DB::table('egg_invoices')->count())->toBe(2);
});

test('a database without Mr.Akter only gets the new tables', function () {
    switchToPreWalletEggSchema();
    DB::table('egg_invoice_payments')->delete();
    DB::table('egg_sales')->delete();
    DB::table('egg_invoices')->delete();
    DB::table('egg_buyers')->delete();

    akterWalletMigration()->up();

    expect(Schema::hasTable('egg_cash_handovers'))->toBeTrue();
    expect(DB::table('egg_cash_handovers')->count())->toBe(0);
});
