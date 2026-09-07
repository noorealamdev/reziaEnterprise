<?php

use App\Models\RolePermission;
use Database\Seeders\ProductionSeeder;

test('ProductionSeeder seeds real setup data only, with none of the demo/dummy data', function () {
    $this->seed(ProductionSeeder::class);

    // Real reference and setup data is present.
    $this->assertDatabaseHas('users', ['email' => 'noorealamdev@gmail.com']);
    $this->assertDatabaseHas('service_categories', ['name' => 'Tiffin']);
    $this->assertDatabaseHas('tiffin_departments', ['name' => 'Swing']);
    $this->assertDatabaseHas('tiffin_items', ['name' => 'Egg']);
    $this->assertDatabaseHas('loading_unloading_items', ['name' => 'Big']);
    $this->assertDatabaseHas('companies', ['code' => 'AAL', 'name' => 'Ananta Apparels Ltd']);
    $this->assertDatabaseHas('companies', ['code' => 'SIMBA', 'name' => 'Simba Fashion']);
    expect(RolePermission::count())->toBeGreaterThan(0);

    // None of DatabaseSeeder's demo/dummy data made it in.
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    $this->assertDatabaseCount('job_entries', 0);
    $this->assertDatabaseCount('tiffin_item_purchases', 0);
    $this->assertDatabaseCount('egg_sales', 0);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('invoice_payments', 0);
    $this->assertDatabaseCount('company_purchases', 0);
    $this->assertDatabaseCount('company_agreements', 0);
    $this->assertDatabaseCount('personal_contacts', 0);
});
