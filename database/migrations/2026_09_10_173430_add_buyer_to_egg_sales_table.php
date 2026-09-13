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
        Schema::table('egg_sales', function (Blueprint $table) {
            $table->foreignId('egg_buyer_id')->nullable()->after('buyer_name')->constrained()->nullOnDelete();
        });

        // Backfill: one EggBuyer per distinct existing buyer_name, so no
        // real sale history or buyer identity is lost when the free-text
        // column is dropped below. Raw DB queries, not the Eloquent models,
        // since a migration should never depend on application code that
        // can change shape later.
        $buyerNames = DB::table('egg_sales')
            ->whereNotNull('buyer_name')
            ->where('buyer_name', '!=', '')
            ->distinct()
            ->pluck('buyer_name');

        foreach ($buyerNames as $buyerName) {
            $buyerId = DB::table('egg_buyers')->where('name', $buyerName)->value('id');

            if (! $buyerId) {
                $buyerId = DB::table('egg_buyers')->insertGetId([
                    'name' => $buyerName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('egg_sales')->where('buyer_name', $buyerName)->update(['egg_buyer_id' => $buyerId]);
        }

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->dropColumn('buyer_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('egg_sales', function (Blueprint $table) {
            $table->string('buyer_name')->nullable()->after('sale_rate');
        });

        DB::table('egg_sales')
            ->join('egg_buyers', 'egg_buyers.id', '=', 'egg_sales.egg_buyer_id')
            ->update(['egg_sales.buyer_name' => DB::raw('egg_buyers.name')]);

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('egg_buyer_id');
        });
    }
};
