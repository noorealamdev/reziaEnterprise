<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('newspapers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('journalist_name');
            $table->string('phone', 30);
            $table->string('whatsapp', 30)->nullable();
            // What the client normally pays this newspaper each month —
            // optional, only used to show Due / Partial / Paid per month.
            $table->decimal('monthly_amount', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        // Whoever already has Sazzad access gets the same level of access to
        // the Newspapers page, so the person using Sazzad can use it straight
        // away; it can still be changed in Settings → Roles & Permissions.
        foreach (['view', 'create', 'modify'] as $level) {
            DB::table('role_permissions')
                ->where('permission', "sajjat.{$level}")
                ->pluck('role')
                ->each(fn (string $role) => DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => "sajjat.newspapers.{$level}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'like', 'sajjat.newspapers.%')->delete();
        Schema::dropIfExists('newspapers');
    }
};
