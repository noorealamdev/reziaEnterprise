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
            $table->foreignId('egg_in_charge_id')->nullable()->after('in_charge')->constrained()->nullOnDelete();
        });

        // Backfill: one EggInCharge per distinct existing in_charge name, so
        // no real sale history or identity is lost when the free-text
        // column is dropped below — same pattern used for buyer_name ->
        // egg_buyer_id. Raw DB queries, not the Eloquent models, since a
        // migration should never depend on application code that can
        // change shape later.
        $inChargeNames = DB::table('egg_sales')
            ->whereNotNull('in_charge')
            ->where('in_charge', '!=', '')
            ->distinct()
            ->pluck('in_charge');

        foreach ($inChargeNames as $inChargeName) {
            $inChargeId = DB::table('egg_in_charges')->where('name', $inChargeName)->value('id');

            if (! $inChargeId) {
                $inChargeId = DB::table('egg_in_charges')->insertGetId([
                    'name' => $inChargeName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('egg_sales')->where('in_charge', $inChargeName)->update(['egg_in_charge_id' => $inChargeId]);
        }

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->dropColumn('in_charge');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('egg_sales', function (Blueprint $table) {
            $table->string('in_charge')->nullable()->after('payment_status');
        });

        DB::table('egg_sales')
            ->join('egg_in_charges', 'egg_in_charges.id', '=', 'egg_sales.egg_in_charge_id')
            ->update(['egg_sales.in_charge' => DB::raw('egg_in_charges.name')]);

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('egg_in_charge_id');
        });
    }
};
