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
        Schema::create('personal_contacts', function (Blueprint $table) {
            $table->id();
            // Owned by exactly one Super Admin, never shared — this whole
            // feature is a private ledger, separate from the business data
            // every other table in this app holds. See personal-ledger.md.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('factory_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_contacts');
    }
};
