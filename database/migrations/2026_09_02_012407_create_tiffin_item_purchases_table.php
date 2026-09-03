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
        Schema::create('tiffin_item_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiffin_item_id')->constrained()->restrictOnDelete();
            $table->date('purchase_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('cost_rate', 10, 2);
            $table->decimal('cost_amount', 12, 2);
            $table->string('supplier_name')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tiffin_item_id', 'purchase_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tiffin_item_purchases');
    }
};
