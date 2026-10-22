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
        Schema::create('canteen_entries', function (Blueprint $table) {
            $table->id();
            // One row per day, same shape as the spreadsheet this replaces
            // (Date / Total Purchases / Total Profit / Daily Cost) — unique
            // so a day can only be logged once, edited rather than
            // duplicated.
            $table->date('entry_date')->unique();
            $table->decimal('total_purchases', 12, 2);
            $table->decimal('total_profit', 12, 2);
            $table->decimal('daily_cost', 12, 2);
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
        Schema::dropIfExists('canteen_entries');
    }
};
