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
        Schema::create('egg_buyer_payments', function (Blueprint $table) {
            $table->id();
            // Cash actually received from this buyer against their running
            // outstanding balance — not tied to one specific sale, since a
            // buyer's Due sales accumulate over time and a payment may only
            // partly settle them. restrictOnDelete, same reasoning as
            // company_purchase_payments.company_purchase_id: a buyer with
            // real payment history against them can't be deleted out from
            // under it.
            $table->foreignId('egg_buyer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egg_buyer_payments');
    }
};
