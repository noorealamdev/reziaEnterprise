<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function restoreEggSalesMigration(): object
{
    return require database_path('migrations/2026_10_31_151855_restore_egg_sales_system.php');
}

/**
 * Puts the schema back to how it looked while the ledger was live.
 */
function switchToEggLedgerSchema(): void
{
    foreach (['egg_sales', 'egg_invoice_payments', 'egg_invoices', 'egg_sales_archive', 'egg_buyers'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('egg_sale_transactions', function (Blueprint $table) {
        $table->id();
        $table->date('transaction_date');
        $table->string('type');
        $table->decimal('quantity', 10, 2);
        $table->decimal('rate', 10, 2)->nullable();
        $table->decimal('amount', 12, 2)->nullable();
        $table->string('description');
        $table->text('remarks')->nullable();
        $table->foreignId('created_by')->nullable();
        $table->timestamps();
    });
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertRecoveredLedgerRow(array $overrides = []): void
{
    DB::table('egg_sale_transactions')->insert([
        'transaction_date' => '2026-10-12',
        'type' => 'sold',
        'quantity' => 60,
        'rate' => 12.20,
        'amount' => 732,
        'description' => 'Mr.Akter',
        'remarks' => 'Keep this remark',
        'created_at' => '2026-10-13 12:42:28',
        'updated_at' => '2026-10-21 02:51:22',
        ...$overrides,
    ]);
}

beforeEach(function () {
    User::factory()->create(['id' => 1]);
    User::factory()->create(['id' => 3]);
});

test('a ledger sale returns with its recovered id, buyer, in-charge and payment status', function () {
    switchToEggLedgerSchema();
    insertRecoveredLedgerRow();

    restoreEggSalesMigration()->up();

    $sale = DB::table('egg_sales')->sole();

    expect($sale->id)->toBe(44);
    expect($sale->payment_status)->toBe('due');
    expect(DB::table('egg_buyers')->where('id', $sale->egg_buyer_id)->value('name'))->toBe('Mr.Akter');
    expect(DB::table('egg_in_charges')->where('id', $sale->egg_in_charge_id)->value('name'))->toBe('Mr.Akter');
    expect((float) $sale->sale_rate)->toBe(12.2);
    expect((float) $sale->sale_amount)->toBe(732.0);
    expect($sale->remarks)->toBe('Keep this remark');
    expect(Schema::hasTable('egg_sale_transactions'))->toBeFalse();
});

test('a ledger row missing from the recovered data stops the restore and leaves the ledger untouched', function () {
    switchToEggLedgerSchema();
    insertRecoveredLedgerRow();
    insertRecoveredLedgerRow(['amount' => 999, 'created_at' => '2026-10-30 10:00:00']);

    expect(fn () => restoreEggSalesMigration()->up())->toThrow(RuntimeException::class, 'nothing was changed');

    expect(DB::table('egg_sale_transactions')->count())->toBe(2);
    expect(Schema::hasTable('egg_sales'))->toBeFalse();
});

test('a payment row in the ledger stops the restore rather than being dropped', function () {
    switchToEggLedgerSchema();
    insertRecoveredLedgerRow(['type' => 'payment']);

    expect(fn () => restoreEggSalesMigration()->up())->toThrow(RuntimeException::class, 'nothing was changed');

    expect(DB::table('egg_sale_transactions')->count())->toBe(1);
});

test('existing egg tables stop the restore and keep their rows', function () {
    DB::table('egg_buyers')->insert(['name' => 'Existing Buyer']);

    expect(fn () => restoreEggSalesMigration()->up())->toThrow(RuntimeException::class, 'already exists');

    expect(DB::table('egg_buyers')->where('name', 'Existing Buyer')->exists())->toBeTrue();
});

test('a failure while copying rows removes the new tables and keeps the ledger', function () {
    switchToEggLedgerSchema();
    insertRecoveredLedgerRow();
    User::query()->whereKey(3)->delete();

    expect(fn () => restoreEggSalesMigration()->up())->toThrow(Exception::class);

    expect(Schema::hasTable('egg_sales'))->toBeFalse();
    expect(Schema::hasTable('egg_buyers'))->toBeFalse();
    expect(DB::table('egg_sale_transactions')->count())->toBe(1);
});
