<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Tiffin', 'invoice_code' => 'TIF', 'unit_label' => 'pieces', 'sort_order' => 1],
            ['name' => 'Diesel Oil Supply', 'invoice_code' => 'Diesel', 'unit_label' => 'litres', 'sort_order' => 2],
            ['name' => 'ETP Rubbish Removing', 'invoice_code' => 'RRW', 'unit_label' => 'trips', 'sort_order' => 3],
            ['name' => 'Daily Basic Labour', 'invoice_code' => 'DiBL', 'unit_label' => 'workers', 'sort_order' => 4],
            ['name' => 'Loading Unloading', 'invoice_code' => 'Load-Unload', 'unit_label' => 'trips', 'sort_order' => 5],
            ['name' => 'Embroidery & Print', 'invoice_code' => 'EMB', 'unit_label' => 'pieces', 'sort_order' => 6],
            ['name' => 'Construction Material Supply', 'invoice_code' => 'CMS', 'unit_label' => 'units', 'sort_order' => 7],
            ['name' => 'ETP Eid Holiday', 'invoice_code' => 'ETP-EID', 'unit_label' => 'project', 'sort_order' => 8],
        ];

        foreach ($categories as $category) {
            ServiceCategory::query()->updateOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
