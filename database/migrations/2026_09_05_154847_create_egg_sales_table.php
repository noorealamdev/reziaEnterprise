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
        Schema::create('egg_sales', function (Blueprint $table) {
            $table->id();
            $table->date('sale_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('sale_rate', 10, 2);
            $table->decimal('sale_amount', 12, 2);
            $table->string('buyer_name')->nullable();
            // 'cash' (paid at time of sale) | 'due' (outstanding) | 'paid'
            // (was due, since settled) — a simple status, not a partial-
            // payment ledger like Personal Ledger or Invoice payments.
            $table->string('payment_status')->default('cash');
            // Free text, same as Job Entry's in_charge — whoever handled
            // this particular sale, not tied to a fixed User list.
            $table->string('in_charge')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('sale_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egg_sales');
    }
};
