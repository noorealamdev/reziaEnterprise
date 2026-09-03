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
        Schema::create('tiffin_department_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiffin_department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiffin_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tiffin_department_id', 'tiffin_item_id'], 'tiffin_department_items_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tiffin_department_items');
    }
};
