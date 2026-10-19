<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/service-summary')->assertRedirect('/login');
});

test('a single-day range shows one row per department for Tiffin on that day', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 40,
        'cost_rate' => 5,
        'bill_rate' => 0,
        'cost_amount' => 200,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 40,
        'cost_rate' => 20.74,
        'bill_rate' => 30,
        'cost_amount' => 829.5,
        'bill_amount' => 1200,
    ]);
    // A different day must not leak into a single-day range.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-16',
        'bill_amount' => 999,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertSee('Swing')
        ->assertSee('Banana, Egg', false)
        ->assertSee('40.00')
        ->assertSee('1,200.00')
        ->assertDontSee('999.00');
});

test('a multi-day range shows every day inside it as its own row, with a grand total', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-10',
        'supply_type' => 'Egg',
        'quantity' => 40,
        'bill_rate' => 30,
        'bill_amount' => 1200,
        'cost_amount' => 800,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-12',
        'supply_type' => 'Egg',
        'quantity' => 30,
        'bill_rate' => 30,
        'bill_amount' => 900,
        'cost_amount' => 600,
    ]);
    // Outside the chosen range — must not be included.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-18',
        'bill_amount' => 5000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id)
        ->set('fromDate', '2026-08-10')
        ->set('toDate', '2026-08-16');

    $component
        // Both days appear as their own rows/headers, not collapsed into
        // one summed row for the range.
        ->assertSeeInOrder(['Monday, 10 August 2026', 'Wednesday, 12 August 2026'])
        // Each day keeps its own headcount and bill.
        ->assertSee('40.00')
        ->assertSee('1,200.00')
        ->assertSee('900.00')
        // Grand Total still sums bill across every day shown: 1,200 + 900.
        ->assertSee('2,100.00')
        ->assertDontSee('5,000.00');
});

test('a whole-month range shows every day in that month as its own row, with a grand total', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-01',
        'supply_type' => 'Egg',
        'quantity' => 40,
        'bill_rate' => 30,
        'bill_amount' => 1200,
        'cost_amount' => 800,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-31',
        'supply_type' => 'Egg',
        'quantity' => 20,
        'bill_rate' => 30,
        'bill_amount' => 600,
        'cost_amount' => 400,
    ]);
    // The next month must not be included.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-09-01',
        'bill_amount' => 5000,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id)
        ->set('fromDate', '2026-08-01')
        ->set('toDate', '2026-08-31')
        // Both days appear as their own rows/headers, not collapsed into
        // one summed row for the month.
        ->assertSeeInOrder(['Saturday, 01 August 2026', 'Monday, 31 August 2026'])
        // Each day keeps its own headcount and bill.
        ->assertSee('40.00')
        ->assertSee('1,200.00')
        ->assertSee('20.00')
        ->assertSee('600.00')
        // Grand Total still sums bill across every day shown: 1,200 + 600.
        ->assertSee('1,800.00')
        ->assertDontSee('5,000.00');
});

test('every day of a 31 day month fits on a single page', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    // August has 31 days — the longest a calendar month can be. The page
    // size (31) must cover it so a full month is always visible without
    // paging.
    foreach (range(1, 31) as $day) {
        JobEntry::factory()->create([
            'company_id' => $company->id,
            'service_category_id' => $tiffin->id,
            'tiffin_department_id' => $swing->id,
            'entry_date' => sprintf('2026-08-%02d', $day),
            'supply_type' => 'Egg',
            'quantity' => 10,
            'bill_rate' => 30,
            'bill_amount' => 300,
            'cost_amount' => 200,
        ]);
    }

    $this->actingAs($user);

    $component = Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id)
        ->set('fromDate', '2026-08-01')
        ->set('toDate', '2026-08-31');

    // All 31 days render on page 1...
    expect($component->viewData('days'))->toHaveCount(31);
    // ...and the Grand Total accounts for every one of them (31 x 300).
    expect($component->viewData('totals')['bill'])->toBe(9300.0);
    $component
        ->assertSee('9,300.00')
        ->assertSeeInOrder(['Saturday, 01 August 2026', 'Monday, 31 August 2026']);
});

test('changing a filter resets pagination back to the first page', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    foreach (range(1, 15) as $day) {
        JobEntry::factory()->create([
            'company_id' => $company->id,
            'service_category_id' => $tiffin->id,
            'tiffin_department_id' => $swing->id,
            'entry_date' => sprintf('2026-08-%02d', $day),
            'supply_type' => 'Egg',
            'bill_amount' => 300,
        ]);
    }

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id)
        ->set('fromDate', '2026-08-01')
        ->set('toDate', '2026-08-15')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('toDate', '2026-08-16')
        ->assertSet('paginators.page', 1);
});

test('changing the category resets pagination back to the first page', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $labour = makeServiceCategory('Daily Basic Labour');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    foreach (range(1, 15) as $day) {
        JobEntry::factory()->create([
            'company_id' => $company->id,
            'service_category_id' => $tiffin->id,
            'tiffin_department_id' => $swing->id,
            'entry_date' => sprintf('2026-08-%02d', $day),
            'supply_type' => 'Egg',
            'bill_amount' => 300,
        ]);
    }

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id)
        ->set('fromDate', '2026-08-01')
        ->set('toDate', '2026-08-15')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('categoryFilter', (string) $labour->id)
        ->assertSet('paginators.page', 1);
});

test('loading the page with no range in the URL defaults to month-to-date', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');

    $this->actingAs($user);

    $component = Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id);

    $component
        ->assertSet('fromDate', now()->startOfMonth()->toDateString())
        ->assertSet('toDate', now()->toDateString());
});

test('the today preset shows only todays entries', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->subDay()->toDateString(),
        'bill_amount' => 9999,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->call('applyPreset', 'today')
        ->assertSet('fromDate', now()->toDateString())
        ->assertSet('toDate', now()->toDateString())
        ->assertSee('1,000.00')
        ->assertDontSee('9,999.00');
});

test('the last 7 days preset includes exactly the last 7 days, not the 8th day back', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->subDays(6)->toDateString(),
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->subDays(8)->toDateString(),
        'bill_amount' => 9999,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->call('applyPreset', 'last_7_days')
        ->assertSee('1,000.00')
        ->assertDontSee('9,999.00');
});

test('the last 30 days preset includes exactly the last 30 days, not the 31st day back', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->subDays(29)->toDateString(),
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->subDays(31)->toDateString(),
        'bill_amount' => 9999,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->call('applyPreset', 'last_30_days')
        ->assertSee('1,000.00')
        ->assertDontSee('9,999.00');
});

test('a preset is highlighted as active only when the picked range matches it exactly', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    $this->actingAs($user);

    $component = Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->call('applyPreset', 'today');

    expect($component->viewData('activePreset'))->toBe('today');

    // Hand-editing the From date breaks the match — no preset is "active"
    // once the range no longer corresponds to one.
    $component->set('fromDate', now()->subDays(3)->toDateString());
    expect($component->viewData('activePreset'))->toBeNull();
});

test('a custom date range shows every day inside it, inclusive of both endpoints', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => '2026-08-05',
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => '2026-08-10',
        'bill_amount' => 500,
    ]);
    // Outside the chosen range on both sides — neither may leak in.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => '2026-08-04',
        'bill_amount' => 9999,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => '2026-08-11',
        'bill_amount' => 8888,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->set('fromDate', '2026-08-05')
        ->set('toDate', '2026-08-10')
        ->assertSee('1,000.00')
        ->assertSee('500.00')
        // Grand Total: 1,000 + 500, never the out-of-range entries.
        ->assertSee('1,500.00')
        ->assertDontSee('9,999.00')
        ->assertDontSee('8,888.00');
});

test('a range where To is before From is swapped instead of showing nothing', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => '2026-08-07',
        'bill_amount' => 750,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->set('fromDate', '2026-08-10')
        ->set('toDate', '2026-08-05')
        ->assertSee('750.00');
});

test('a non tiffin category shows one row per entry, not grouped by department', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'tiffin_department_id' => null,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Daily Basic Labour',
        'quantity' => 10,
        'cost_rate' => 550,
        'bill_rate' => 650,
        'cost_amount' => 5500,
        'bill_amount' => 6500,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'tiffin_department_id' => null,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Daily Basic Labour',
        'quantity' => 5,
        'cost_rate' => 550,
        'bill_rate' => 650,
        'cost_amount' => 2750,
        'bill_amount' => 3250,
    ]);

    $this->actingAs($user);

    $component = Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15');

    $days = $component->viewData('days');
    expect($days->first()['rows'])->toHaveCount(2);
    $component
        ->assertSee('10.00')
        ->assertSee('5.00')
        ->assertSee('6,500.00')
        ->assertSee('3,250.00')
        // Grand Total: 6,500 + 3,250.
        ->assertSee('9,750.00');
});

test('a loading unloading entrys shipment tiffin cost is shown, and reflected in profit', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $loadingUnloading = makeServiceCategory('Loading Unloading');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $loadingUnloading->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Big',
        'quantity' => 18,
        'cost_rate' => 700,
        'bill_rate' => 850,
        'cost_amount' => 12600,
        'bill_amount' => 15300,
        'shipment_tiffin_cost' => 500,
        'profit_amount' => 2200, // 15300 - 12600 - 500
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $loadingUnloading->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertSee('Shipment Tiffin Cost: 500.00', false)
        // Cost includes the shipment cost (12,600 + 500) so Cost + Profit
        // reconciles to Bill at a glance — never added to Bill itself.
        ->assertSee('13,100.00')
        ->assertDontSee('15,800.00')
        // Profit already has the shipment cost deducted (2,700 - 500).
        ->assertSee('2,200.00')
        // Bill is untouched by the shipment cost.
        ->assertSee('15,300.00');
});

test('an entry with no shipment tiffin cost shows no shipment cost note', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $loadingUnloading = makeServiceCategory('Loading Unloading');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $loadingUnloading->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Big',
        'shipment_tiffin_cost' => null,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $loadingUnloading->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertDontSee('Shipment Tiffin Cost');
});

test('an entrys remarks are shown beside the item name', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Diesel',
        'remarks' => 'Delivered late due to road closure',
        'bill_amount' => 5000,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $category->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertSee('Delivered late due to road closure');
});

test('a category with extra fields shows them beside the item name', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $embroidery = makeServiceCategory('Embroidery & Print');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $embroidery->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Embroidery Work',
        'buyer' => 'American Eagle',
        'style' => '6856',
        'bill_amount' => 4500,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $embroidery->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertSee('Embroidery Work')
        ->assertSee('American Eagle — Style 6856', false);
});

test('a Combustible Material entrys collected-from company is shown beside the item name', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $source = Company::factory()->create(['name' => 'Some Textile Factory']);
    $combustible = makeServiceCategory('Combustible Material');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $combustible->id,
        'collected_from_company_id' => $source->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Floor Dust',
        'bill_amount' => 3654,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $combustible->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertSee('Floor Dust')
        ->assertSee('Collected from Some Textile Factory');
});

test('a Simba Daily Basic Labour entrys unit is shown beside the item name', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['code' => 'SFL']);
    $labour = makeServiceCategory('Daily Basic Labour');
    $unit1 = TiffinDepartment::create(['name' => 'Simba Unit-01']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'tiffin_department_id' => $unit1->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Daily Basic Labour',
        'bill_amount' => 6500,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertSee('Daily Basic Labour')
        ->assertSee('Simba Unit-01');
});

test('selecting a category excludes every other categorys entries for the same company and day', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');
    $diesel = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $diesel->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 9999,
    ]);

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $labour->id)
        ->set('fromDate', '2026-08-15')
        ->set('toDate', '2026-08-15')
        ->assertSee('1,000.00')
        ->assertDontSee('9,999.00');
});

test('the report prompts for a company and category before showing anything', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);

    $this->actingAs($user);

    // Neither filter set.
    Volt::test('service-summary.service-summary-report')
        ->assertSee('Select a company and category');

    // Only the company set — still prompted.
    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('Select a company and category');

    // Only the category set — still prompted.
    Volt::test('service-summary.service-summary-report')
        ->set('categoryFilter', (string) $labour->id)
        ->assertSee('Select a company and category');
});
