<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\InCharge;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use App\Models\TiffinDepartment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class JobEntrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * DatabaseSeeder runs inside Model::withoutEvents() (via
     * WithoutModelEvents), so JobEntry's `saving` hook that normally
     * computes profit_amount will NOT fire here — every entry() call
     * computes cost/bill/profit amounts manually.
     */
    public function run(): void
    {
        $company = Company::where('code', 'AAL')->first();

        if (! $company) {
            return;
        }

        $categories = ServiceCategory::pluck('id', 'name');
        $swing = TiffinDepartment::where('name', 'Swing')->first();
        $washWorker = TiffinDepartment::where('name', 'Wash Worker')->first();
        $monir = InCharge::where('name', 'Mr. Monir')->first();
        $sagor = InCharge::where('name', 'Sagor Vai')->first();

        $today = now();

        // Only Egg bills (a fixed rate per person, matching the company's
        // tiffin_bill_rate) — Banana/Bread are cost-tracking only.
        if ($swing && $categories->has('Tiffin')) {
            $this->tiffinDay($company, $categories['Tiffin'], $swing, $today->copy(), [
                'Banana' => [40, 5, 0],
                'Egg' => [40, 11.5, 30],
                'Bread' => [40, 7.8, 0],
            ], $monir);

            $this->tiffinDay($company, $categories['Tiffin'], $swing, $today->copy()->subDay(), [
                'Banana' => [38, 5, 0],
                'Egg' => [38, 11.5, 30],
                'Bread' => [38, 7.8, 0],
            ], $monir);

            $this->tiffinDay($company, $categories['Tiffin'], $swing, $today->copy()->subDays(2), [
                'Banana' => [42, 5, 0],
                'Egg' => [42, 11.5, 30],
                'Bread' => [42, 7.8, 0],
            ], $monir);
        }

        if ($washWorker && $categories->has('Tiffin')) {
            // Swing already seeded these two days above — the egg buffer
            // goes there (alphabetically first), so Wash Worker's own Egg
            // row carries none.
            $this->tiffinDay($company, $categories['Tiffin'], $washWorker, $today->copy(), [
                'Banana' => [25, 5, 0],
                'Egg' => [25, 11.5, 30],
                'Bread' => [25, 7.8, 0],
            ], $sagor, includeEggBuffer: false);

            $this->tiffinDay($company, $categories['Tiffin'], $washWorker, $today->copy()->subDay(), [
                'Banana' => [22, 5, 0],
                'Egg' => [22, 11.5, 30],
                'Bread' => [22, 7.8, 0],
            ], $sagor, includeEggBuffer: false);
        }

        if ($categories->has('Daily Basic Labour')) {
            $this->entry($company, $categories['Daily Basic Labour'], $today->copy(), [
                'supply_type' => 'Daily Basic Labour',
                'quantity' => 12,
                'cost_rate' => 550,
                'bill_rate' => 650,
                'in_charge_id' => $monir?->id,
            ]);

            $this->entry($company, $categories['Daily Basic Labour'], $today->copy()->subDay(), [
                'supply_type' => 'Daily Basic Labour',
                'quantity' => 10,
                'cost_rate' => 550,
                'bill_rate' => 650,
                'in_charge_id' => $monir?->id,
            ]);

            $this->entry($company, $categories['Daily Basic Labour'], $today->copy()->subDays(3), [
                'supply_type' => 'Daily Basic Labour',
                'quantity' => 0,
                'cost_rate' => 0,
                'bill_rate' => 0,
                'is_off_day' => true,
                'remarks' => 'Off day due to public holiday',
            ]);
        }

        if ($categories->has('Diesel Oil Supply')) {
            $this->entry($company, $categories['Diesel Oil Supply'], $today->copy(), [
                'supply_type' => 'Diesel',
                'quantity' => 200,
                'cost_rate' => 92,
                'bill_rate' => 108,
                'challan_no' => '5981',
            ]);

            $this->entry($company, $categories['Diesel Oil Supply'], $today->copy()->subDays(4), [
                'supply_type' => 'Diesel',
                'quantity' => 180,
                'cost_rate' => 90,
                'bill_rate' => 105,
                'challan_no' => '5940',
                'remarks' => 'Rate renegotiated after diesel price hike',
            ]);
        }

        if ($categories->has('Loading Unloading')) {
            $this->entry($company, $categories['Loading Unloading'], $today->copy(), [
                'supply_type' => 'Loading Unloading',
                'quantity' => 2,
                'cost_rate' => 3000,
                'bill_rate' => 4200,
                'floor' => 'Mazzanine Floor',
            ]);

            $this->entry($company, $categories['Loading Unloading'], $today->copy()->subDay(), [
                'supply_type' => 'Loading Unloading',
                'quantity' => 1,
                'cost_rate' => 2500,
                'bill_rate' => 3600,
                'floor' => 'Ground Floor',
            ]);
        }

        if ($categories->has('Embroidery & Print')) {
            $this->entry($company, $categories['Embroidery & Print'], $today->copy(), [
                'supply_type' => 'Embroidery Work',
                'quantity' => 500,
                'cost_rate' => 6,
                'bill_rate' => 9,
                'buyer' => 'American Eagle',
                'style' => '6856',
            ]);

            $this->entry($company, $categories['Embroidery & Print'], $today->copy()->subDays(2), [
                'supply_type' => 'Embroidery Work',
                'quantity' => 350,
                'cost_rate' => 6,
                'bill_rate' => 9,
                'buyer' => 'GAP',
                'style' => '7421',
            ]);
        }

        if ($categories->has('Construction Material Supply')) {
            $this->entry($company, $categories['Construction Material Supply'], $today->copy(), [
                'supply_type' => 'Sand',
                'quantity' => 10,
                'cost_rate' => 1200,
                'bill_rate' => 1500,
            ]);

            $this->entry($company, $categories['Construction Material Supply'], $today->copy()->subDay(), [
                'supply_type' => 'Brick',
                'quantity' => 2000,
                'cost_rate' => 9,
                'bill_rate' => 12,
            ]);
        }

        if ($categories->has('ETP Rubbish Removing')) {
            $this->entry($company, $categories['ETP Rubbish Removing'], $today->copy(), [
                'supply_type' => 'Dump Truck',
                'quantity' => 3,
                'cost_rate' => 1800,
                'bill_rate' => 2400,
            ]);
        }

        if ($categories->has('ETP Eid Holiday')) {
            $this->entry($company, $categories['ETP Eid Holiday'], $today->copy()->subDays(30), [
                'supply_type' => 'ETP Tank Cleaning',
                'quantity' => 1,
                'cost_rate' => 80000,
                'bill_rate' => 100000,
                'company_adv_payment' => 50000,
            ]);
        }
    }

    /**
     * @param  array<string, array{0: float, 1: float, 2: float}>  $items  Keyed by item name: [quantity, cost_rate, bill_rate]. For Egg, quantity is the day's headcount — the fixed egg buffer (config('tiffin.egg_buffer_quantity')) is added on top for the stored quantity/cost, matching the real Tiffin batch form, while bill_amount stays headcount × rate.
     * @param  bool  $includeEggBuffer  The +5 buffer is sent once per company per day, not once per department — pass false for every department after the first one being seeded for the same company/day.
     */
    private function tiffinDay(Company $company, int $categoryId, TiffinDepartment $department, Carbon $date, array $items, ?InCharge $inCharge, bool $includeEggBuffer = true): void
    {
        foreach ($items as $name => [$headcountOrQuantity, $costRate, $billRate]) {
            $isEgg = $name === 'Egg';
            $storedQuantity = ($isEgg && $includeEggBuffer) ? $headcountOrQuantity + config('tiffin.egg_buffer_quantity') : $headcountOrQuantity;

            $this->entry($company, $categoryId, $date, [
                'supply_type' => $name,
                'quantity' => $storedQuantity,
                'cost_rate' => $costRate,
                'bill_rate' => $billRate,
                'bill_amount_basis' => $headcountOrQuantity,
                'tiffin_department_id' => $department->id,
                'in_charge_id' => $inCharge?->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(Company $company, int $categoryId, Carbon $date, array $attributes): void
    {
        $quantity = (float) ($attributes['quantity'] ?? 0);
        $costRate = (float) ($attributes['cost_rate'] ?? 0);
        $billRate = (float) ($attributes['bill_rate'] ?? 0);
        // Bill amount is normally quantity × rate, but Egg's stored
        // quantity includes the fixed buffer while its bill still runs off
        // the real headcount — bill_amount_basis carries that distinction
        // through from tiffinDay(), defaulting to quantity everywhere else.
        $billBasis = (float) ($attributes['bill_amount_basis'] ?? $quantity);
        $costAmount = round($quantity * $costRate, 2);
        $billAmount = round($billBasis * $billRate, 2);

        unset($attributes['bill_amount_basis']);

        JobEntry::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'service_category_id' => $categoryId,
                'tiffin_department_id' => $attributes['tiffin_department_id'] ?? null,
                'supply_type' => $attributes['supply_type'],
                'buyer' => $attributes['buyer'] ?? null,
                'entry_date' => $date->toDateString(),
            ],
            array_merge($attributes, [
                'cost_amount' => $costAmount,
                'bill_amount' => $billAmount,
                'profit_amount' => round($billAmount - $costAmount, 2),
                'is_off_day' => $attributes['is_off_day'] ?? false,
            ])
        );
    }
}
