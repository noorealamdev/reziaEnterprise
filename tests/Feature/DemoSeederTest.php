<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use Database\Seeders\DatabaseSeeder;

test('DatabaseSeeder seeds Simba Fashion Ltd under its real code and wires SimbaFashionSeeder to it', function () {
    $this->seed(DatabaseSeeder::class);

    // Must match the real production company exactly, or SimbaFashionSeeder
    // and CompanyAgreementSeeder silently no-op (their guard clause returns
    // early when the company isn't found by code) — exactly how this broke
    // once before, when the seeders used a demo-only code that drifted from
    // the real one.
    $simba = Company::where('code', 'SFL')->first();
    expect($simba)->not->toBeNull();
    expect($simba->name)->toBe('Simba Fashion Ltd');

    // SimbaFashionSeeder actually ran against it (not silently skipped).
    expect(Invoice::where('company_id', $simba->id)->count())->toBeGreaterThan(0);

    // Simba is only subscribed to its own 4 units, never Ananta's Swing/Wash
    // Worker departments — a plain "sync every department" would wrongly
    // mix the two companies' departments together.
    expect($simba->tiffinDepartments()->pluck('name')->sort()->values()->all())
        ->toBe(['Simba Unit-01', 'Simba Unit-02', 'Simba Unit-03', 'Simba Unit-04']);

    // Simba's seeded Daily Basic Labour bills are tagged with a real unit,
    // demonstrating the per-unit billing feature out of the box.
    $unit1 = TiffinDepartment::where('name', 'Simba Unit-01')->firstOrFail();
    expect(JobEntry::where('company_id', $simba->id)->where('tiffin_department_id', $unit1->id)->count())
        ->toBeGreaterThan(0);
});
