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
        Schema::table('invoices', function (Blueprint $table) {
            // What the bill behind a manually-entered past invoice actually
            // cost — optional, since older past bills were recorded with no
            // cost data. Lets Total Cost/Total Profit be shown for a manual
            // invoice the same way they already are for a generated one,
            // instead of always reading as zero.
            $table->decimal('manual_cost_amount', 12, 2)->nullable()->after('manual_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('manual_cost_amount');
        });
    }
};
