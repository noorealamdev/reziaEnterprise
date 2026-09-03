<?php

namespace Database\Seeders;

use App\Models\TiffinDepartment;
use App\Models\TiffinDepartmentItem;
use App\Models\TiffinItem;
use Illuminate\Database\Seeder;

class TiffinItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [];

        foreach (['Egg', 'Banana', 'Bread', 'Milk'] as $name) {
            $items[$name] = TiffinItem::query()->updateOrCreate(['name' => $name]);
        }

        $swing = TiffinDepartment::where('name', 'Swing')->first();
        $washWorker = TiffinDepartment::where('name', 'Wash Worker')->first();

        if (! $swing || ! $washWorker) {
            return;
        }

        // Swing and Wash Worker share the same items (client's spreadsheet
        // note: "same column"): Banana, Egg and Bread, quantities entered
        // by hand on each Job Entry.
        $this->assignItem($swing, $items['Banana'], sortOrder: 1);
        $this->assignItem($swing, $items['Egg'], sortOrder: 2);
        $this->assignItem($swing, $items['Bread'], sortOrder: 3);

        $this->assignItem($washWorker, $items['Banana'], sortOrder: 1);
        $this->assignItem($washWorker, $items['Egg'], sortOrder: 2);
        $this->assignItem($washWorker, $items['Bread'], sortOrder: 3);

        // Milk is no longer assigned to Wash Worker; drop any stale
        // assignment left over from before this rule was corrected.
        TiffinDepartmentItem::where('tiffin_department_id', $washWorker->id)
            ->where('tiffin_item_id', $items['Milk']->id)
            ->delete();
    }

    private function assignItem(TiffinDepartment $department, TiffinItem $item, int $sortOrder): void
    {
        TiffinDepartmentItem::query()->updateOrCreate(
            [
                'tiffin_department_id' => $department->id,
                'tiffin_item_id' => $item->id,
            ],
            [
                'sort_order' => $sortOrder,
            ]
        );
    }
}
