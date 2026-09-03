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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('due');
            $table->decimal('vat_percent', 5, 2)->nullable();
            $table->date('paid_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('signed_copy_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'period_start'], 'invoices_company_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
