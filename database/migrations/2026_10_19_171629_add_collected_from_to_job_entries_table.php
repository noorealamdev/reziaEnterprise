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
        Schema::table('job_entries', function (Blueprint $table) {
            // Combustible Material only — which company Progress Apparels
            // Ltd collected the material from (e.g. the textile factory
            // that gave away its floor dust or fire wood). References the
            // same companies table, not a separate catalog — restricted
            // rather than cascaded, so that company can't be deleted while
            // still referenced here.
            $table->foreignId('collected_from_company_id')->nullable()->after('floor')
                ->constrained('companies')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_from_company_id');
        });
    }
};
