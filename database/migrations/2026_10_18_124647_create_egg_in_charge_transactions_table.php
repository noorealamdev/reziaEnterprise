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
        Schema::create('egg_in_charge_transactions', function (Blueprint $table) {
            $table->id();
            // Cash this in-charge person has handled isn't recorded here —
            // it's read straight off egg_sales (every Cash sale under them),
            // so it's never re-typed. This ledger only ever reduces what
            // they owe: 'expense' (spent on something else on the
            // company's behalf) or 'remittance' (cash actually handed back
            // to the office) — restrictOnDelete, same reasoning as
            // egg_buyer_payments.egg_buyer_id: an in-charge with real
            // ledger history can't be deleted out from under it.
            $table->foreignId('egg_in_charge_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('transaction_date');
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index(['egg_in_charge_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egg_in_charge_transactions');
    }
};
