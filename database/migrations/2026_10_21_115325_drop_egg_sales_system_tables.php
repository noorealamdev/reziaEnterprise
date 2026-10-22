<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The old Egg Sales system (buyers, per-sale payment status, the
     * In-Charge cash wallet) is replaced by the simpler egg_sale_transactions
     * ledger — every row here was already copied forward by the previous
     * migration. Dropped in FK-dependency order: tables that reference
     * egg_buyers/egg_in_charges first, then the two they reference.
     */
    public function up(): void
    {
        Schema::dropIfExists('egg_sales');
        Schema::dropIfExists('egg_buyer_payments');
        Schema::dropIfExists('egg_in_charge_transactions');
        Schema::dropIfExists('egg_buyers');
        Schema::dropIfExists('egg_in_charges');
    }

    /**
     * Reverse the migrations.
     *
     * Not reversible — the tables' real data is gone the moment they're
     * dropped, so recreating empty tables here would be misleading rather
     * than helpful.
     */
    public function down(): void
    {
        //
    }
};
