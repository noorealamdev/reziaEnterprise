<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    /**
     * Seeds only what a live deployment actually needs to start
     * operating — real reference data and the two known clients — with
     * none of DatabaseSeeder's demo/dummy data (fake job entries, egg
     * stock, Simba's illustrative purchase/invoice history, agreements,
     * personal ledger contacts, or the test@example.com user).
     *
     * Run this instead of the default seeder on first production setup:
     *   php artisan db:seed --class=ProductionSeeder --force
     *
     * The admin account this creates (via AdminUserSeeder) still uses
     * its local-dev placeholder password — log in and change it
     * immediately, before entering any real business data.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);
        $this->call(RolePermissionSeeder::class);
        $this->call(ServiceCategorySeeder::class);
        $this->call(TiffinDepartmentSeeder::class);
        $this->call(TiffinItemSeeder::class);
        $this->call(LoadingUnloadingItemSeeder::class);
        $this->call(CompanySeeder::class);
    }
}
