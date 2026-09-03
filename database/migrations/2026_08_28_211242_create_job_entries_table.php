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
        Schema::create('job_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('tiffin_department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('in_charge_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('entry_date');
            $table->string('supply_type');
            $table->string('buyer')->nullable();
            $table->string('style')->nullable();
            $table->string('floor')->nullable();
            $table->string('challan_no')->nullable();
            $table->decimal('company_adv_payment', 10, 2)->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('cost_rate', 10, 2)->nullable();
            $table->decimal('bill_rate', 10, 2)->nullable();
            $table->decimal('cost_amount', 10, 2)->default(0);
            $table->decimal('bill_amount', 10, 2)->default(0);
            $table->decimal('profit_amount', 10, 2)->default(0);
            $table->boolean('is_off_day')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'service_category_id', 'entry_date'], 'job_entries_company_category_date_index');
            $table->index('entry_date', 'job_entries_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_entries');
    }
};
