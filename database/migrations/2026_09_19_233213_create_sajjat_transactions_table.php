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
        Schema::create('sajjat_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            // 'top_up' = money the client gives Sajjat, 'expense' = money he
            // spends. One ledger for both, so each wallet's balance is simply
            // top-ups minus expenses — computed live, never stored.
            $table->string('type', 20);
            // Which of Sajjat's two wallets the money moved through.
            $table->string('wallet', 20);
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index(['wallet', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sajjat_transactions');
    }
};
