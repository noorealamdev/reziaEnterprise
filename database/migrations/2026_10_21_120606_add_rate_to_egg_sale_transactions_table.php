<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Price per egg for a 'sold' entry — the amount column alone couldn't
     * answer "how much per egg did Mr. Akter sell at", so it's tracked
     * explicitly rather than only as the implied amount/quantity ratio.
     *
     * Existing 'sold' rows (copied forward from the old egg_sales table
     * before this column existed) are backfilled as amount ÷ quantity —
     * the old sale_rate itself is gone along with the dropped egg_sales
     * table, but amount was always quantity × sale_rate, so this recovers
     * the same number.
     */
    public function up(): void
    {
        Schema::table('egg_sale_transactions', function (Blueprint $table) {
            $table->decimal('rate', 10, 2)->nullable()->after('quantity');
        });

        DB::table('egg_sale_transactions')
            ->where('type', 'sold')
            ->whereNull('rate')
            ->whereNotNull('amount')
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->get(['id', 'quantity', 'amount'])
            ->each(fn ($row) => DB::table('egg_sale_transactions')
                ->where('id', $row->id)
                ->update(['rate' => round($row->amount / $row->quantity, 2)]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('egg_sale_transactions', function (Blueprint $table) {
            $table->dropColumn('rate');
        });
    }
};
