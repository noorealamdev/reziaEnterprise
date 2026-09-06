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
        Schema::create('personal_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_contact_id')->constrained()->cascadeOnDelete();
            $table->date('payment_date');
            // Cash only, by the client's own description of how these
            // contacts pay — no payment method field, unlike InvoicePayment.
            $table->decimal('amount', 12, 2);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['personal_contact_id', 'payment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_payments');
    }
};
