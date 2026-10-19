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
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('license_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date');
            $table->string('document_path')->nullable();
            $table->text('remarks')->nullable();
            // When the deadline alert last emailed Super Admins about this
            // license — mirrors company_agreements.last_alerted_at so the
            // same dedupe/resend behaviour applies per license.
            $table->timestamp('last_alerted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
