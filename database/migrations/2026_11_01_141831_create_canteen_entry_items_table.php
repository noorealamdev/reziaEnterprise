<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canteen entries gain the item-by-item detail of the daily spreadsheet.
     * Each line keeps the rates it was recorded at, so changing a rate later
     * never rewrites a past day's figures.
     */
    public function up(): void
    {
        Schema::create('canteen_entry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canteen_entry_id')->constrained()->cascadeOnDelete();
            $table->string('item');
            $table->decimal('quantity', 10, 2);
            $table->decimal('purchase_rate', 10, 2)->nullable();
            $table->decimal('purchase_amount', 12, 2)->default(0);
            $table->decimal('profit_rate', 10, 2)->nullable();
            $table->decimal('profit_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['canteen_entry_id', 'item']);
        });

        Schema::table('canteen_entries', function (Blueprint $table) {
            $table->decimal('company_staff_due', 12, 2)->default(0)->after('daily_cost');
        });
    }

    public function down(): void
    {
        Schema::table('canteen_entries', function (Blueprint $table) {
            $table->dropColumn('company_staff_due');
        });

        Schema::dropIfExists('canteen_entry_items');
    }
};
