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
        Schema::create('company_purchase_payments', function (Blueprint $table) {
            $table->id();
            // Rezia paying a company cash to settle what's left of a
            // purchase bill after any Bill Adjustments — the reverse
            // direction of an invoice payment. restrictOnDelete, same as
            // invoice_payments.company_purchase_id: a purchase bill with
            // real payment history against it can't be deleted out from
            // under that history.
            $table->foreignId('company_purchase_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            // A photo/scan of the money receipt as proof this cash was
            // actually handed over — same idea as CompanyPurchase's own
            // memo_path.
            $table->string('receipt_path')->nullable();
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
        Schema::dropIfExists('company_purchase_payments');
    }
};
