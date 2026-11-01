<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gives the new Canteen Manager role its starting permissions on every
     * database. They can be changed afterwards in Settings → Roles & Permissions.
     */
    public function up(): void
    {
        foreach (['canteen.view', 'canteen.create', 'canteen.modify'] as $permission) {
            DB::table('role_permissions')->updateOrInsert(
                ['role' => 'canteen_manager', 'permission' => $permission],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('role', 'canteen_manager')->delete();
    }
};
