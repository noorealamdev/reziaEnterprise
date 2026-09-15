<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_entries', function (Blueprint $table) {
            // Loading Unloading only — what Rezia pays labourers to feed
            // them on a shipment day. A real cost, but never part of what
            // the factory is billed, so it only ever reduces profit_amount,
            // never cost_amount/bill_amount directly.
            $table->decimal('shipment_tiffin_cost', 10, 2)->nullable()->after('company_adv_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_entries', function (Blueprint $table) {
            $table->dropColumn('shipment_tiffin_cost');
        });
    }
};
