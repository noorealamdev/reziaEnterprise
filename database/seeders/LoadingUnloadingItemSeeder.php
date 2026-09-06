<?php

namespace Database\Seeders;

use App\Models\LoadingUnloadingItem;
use Illuminate\Database\Seeder;

class LoadingUnloadingItemSeeder extends Seeder
{
    /**
     * Sourced directly from a real Rezia Enterprise bill to Simba Fashion
     * (Bill No. 230, Jul-2026) — each is its own line item with its own
     * unit of measure, not a single generic "Loading Unloading" quantity.
     */
    public function run(): void
    {
        $items = [
            ['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1],
            ['name' => 'Small', 'unit_label' => 'Cover Van', 'sort_order' => 2],
            ['name' => 'Wash', 'unit_label' => 'Cover Van', 'sort_order' => 3],
            ['name' => 'Wash Big', 'unit_label' => 'Cover Van', 'sort_order' => 4],
            ['name' => 'Machine Set', 'unit_label' => 'Set', 'sort_order' => 5],
            ['name' => 'Daily Labour', 'unit_label' => 'Person', 'sort_order' => 6],
            ['name' => 'Bosa Gari', 'unit_label' => 'Cover Van', 'sort_order' => 7],
        ];

        foreach ($items as $item) {
            LoadingUnloadingItem::updateOrCreate(
                ['name' => $item['name']],
                ['unit_label' => $item['unit_label'], 'is_active' => true, 'sort_order' => $item['sort_order']]
            );
        }
    }
}
