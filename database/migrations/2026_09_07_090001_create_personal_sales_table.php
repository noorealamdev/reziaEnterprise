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
        Schema::create('personal_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_contact_id')->constrained()->cascadeOnDelete();
            $table->date('sale_date');
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['personal_contact_id', 'sale_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_sales');
    }
};
