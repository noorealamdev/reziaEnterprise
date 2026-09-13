<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tiffin_item_purchases', function (Blueprint $table) {
            $table->renameColumn('cost_rate', 'purchase_rate');
            $table->renameColumn('cost_amount', 'purchase_amount');
        });

        Schema::table('tiffin_item_purchases', function (Blueprint $table) {
            // What Tiffin's own cost rate gets locked to for Egg specifically
            // (see TiffinItemPurchase::findFor() callers) — separate from
            // purchase_rate so the office can see the profit margin between
            // what the egg business paid and what it internally "sells" eggs
            // to Tiffin at. Nullable at the DB level only so the backfill
            // below can run before any value exists; the Livewire form
            // requires it for every purchase going forward.
            $table->decimal('sale_rate', 10, 2)->nullable()->after('purchase_rate');
        });

        // Backfill existing purchases at zero markup (sale_rate = purchase_rate)
        // so nothing silently breaks — the office can edit in the real
        // margin per purchase afterward.
        DB::table('tiffin_item_purchases')->update(['sale_rate' => DB::raw('purchase_rate')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tiffin_item_purchases', function (Blueprint $table) {
            $table->dropColumn('sale_rate');
        });

        Schema::table('tiffin_item_purchases', function (Blueprint $table) {
            $table->renameColumn('purchase_rate', 'cost_rate');
            $table->renameColumn('purchase_amount', 'cost_amount');
        });
    }
};
