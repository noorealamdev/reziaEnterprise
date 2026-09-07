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
        Schema::create('egg_wastes', function (Blueprint $table) {
            $table->id();
            // Eggs broken/spoiled and thrown out — beyond the fixed
            // +5/day buffer already baked into Tiffin's own consumption
            // figure, a real loss the office wants to log explicitly so
            // "In Stock Now" stays accurate.
            $table->date('waste_date');
            $table->decimal('quantity', 10, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('waste_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egg_wastes');
    }
};
