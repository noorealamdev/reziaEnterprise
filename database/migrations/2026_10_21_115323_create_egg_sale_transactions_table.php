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
        Schema::create('egg_sale_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            // 'received' (eggs in, e.g. allocated for external sale) |
            // 'sold' (eggs out, to an outside buyer) — same shape as
            // Sajjat's top_up/expense, but the "currency" is eggs.
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            // Money received for a 'sold' transaction — null for
            // 'received', since that's a pure stock movement.
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('description');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egg_sale_transactions');
    }
};
