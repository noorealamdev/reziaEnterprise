<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One-off data backfill ahead of dropping the old Egg Sales tables —
     * every existing sale becomes a 'sold' entry in the new ledger, so the
     * Egg Purchases page's historical "Sold Externally" and "Egg Business
     * Profit" figures don't suddenly change. Raw DB queries, not the
     * Eloquent models, since those models are removed in the same change
     * that ships this migration and a migration must never depend on
     * application code that can disappear later.
     *
     * The buyer/in-charge/payment-status distinction this app used to
     * track per sale has no equivalent in the new ledger (by design — see
     * the Egg Sales redesign conversation) — the buyer's name is folded
     * into the new entry's description instead, as the closest surviving
     * detail.
     */
    public function up(): void
    {
        if (! Schema::hasTable('egg_sales')) {
            return;
        }

        $sales = DB::table('egg_sales')
            ->leftJoin('egg_buyers', 'egg_buyers.id', '=', 'egg_sales.egg_buyer_id')
            ->select('egg_sales.*', 'egg_buyers.name as buyer_name')
            ->orderBy('egg_sales.id')
            ->get();

        foreach ($sales as $sale) {
            DB::table('egg_sale_transactions')->insert([
                'transaction_date' => $sale->sale_date,
                'type' => 'sold',
                'quantity' => $sale->quantity,
                'rate' => $sale->sale_rate,
                'amount' => $sale->sale_amount,
                'description' => $sale->buyer_name ?: 'Egg sale',
                'remarks' => $sale->remarks,
                'created_by' => $sale->created_by,
                'created_at' => $sale->created_at,
                'updated_at' => $sale->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Not meaningfully reversible — rows copied by this migration can't be
     * reliably distinguished from genuine new entries added afterward, so
     * this intentionally does nothing rather than guess and delete real
     * data.
     */
    public function down(): void
    {
        //
    }
};
