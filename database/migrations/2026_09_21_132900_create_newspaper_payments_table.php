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
        Schema::create('newspaper_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newspaper_id')->constrained()->restrictOnDelete();
            // First day of the month this payment is for, same as salary_payments.
            $table->date('for_month');
            $table->decimal('amount', 10, 2);
            $table->date('paid_on');
            // Paid out of Sazzad's balance: which wallet it came from, and the
            // matching expense in his ledger — so the wallet balance drops by
            // exactly this amount and stays the single source of truth.
            $table->string('wallet', 20);
            $table->foreignId('sajjat_transaction_id')->nullable()->constrained('sajjat_transactions')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['newspaper_id', 'for_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newspaper_payments');
    }
};
