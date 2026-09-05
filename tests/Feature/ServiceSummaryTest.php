<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/service-summary')->assertRedirect('/login');
});

test('the daily period shows one row per department for Tiffin on a single day', function () {
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
    // A different day must not leak into the daily view.
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
        ->set('period', 'daily')
        ->set('referenceDate', '2026-08-15')
        ->assertSee('Swing')
        ->assertSee('Banana, Egg', false)
        ->assertSee('40.00')
        ->assertSee('1,200.00')
        ->assertDontSee('999.00');
});

test('the weekly period shows every day in that week as its own row, with a grand total', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    // 2026-08-10 is a Monday — both days fall in the same week.
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
    // The following week must not be included.
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
        ->set('period', 'weekly')
        ->set('referenceDate', '2026-08-11');

    $component
        // Both days appear as their own rows/headers, not collapsed into
        // one summed row for the week.
        ->assertSeeInOrder(['Monday, 10 August 2026', 'Wednesday, 12 August 2026'])
        // Each day keeps its own headcount and bill.
        ->assertSee('40.00')
        ->assertSee('1,200.00')
        ->assertSee('900.00')
        // Grand Total still sums bill across every day shown: 1,200 + 900.
        ->assertSee('2,100.00')
        ->assertDontSee('5,000.00');
});

test('the monthly period shows every day in that month as its own row, with a grand total', function () {
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
        ->set('period', 'monthly')
        ->set('referenceDate', '2026-08-15')
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
        ->set('period', 'monthly')
        ->set('referenceDate', '2026-08-15');

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
        ->set('period', 'monthly')
        ->set('referenceDate', '2026-08-15')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('referenceDate', '2026-08-16')
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
        ->set('period', 'monthly')
        ->set('referenceDate', '2026-08-15')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('categoryFilter', (string) $labour->id)
        ->assertSet('paginators.page', 1);
});

test('navigating to the previous and next period moves the reference date by the selected period', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');

    $this->actingAs($user);

    Volt::test('service-summary.service-summary-report')
        ->set('companyFilter', (string) $company->id)
        ->set('categoryFilter', (string) $tiffin->id)
        ->set('period', 'monthly')
        ->set('referenceDate', '2026-08-15')
        ->call('goToNextPeriod')
        ->assertSet('referenceDate', '2026-09-15')
        ->call('goToPreviousPeriod')
        ->call('goToPreviousPeriod')
        ->assertSet('referenceDate', '2026-07-15');
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
        ->set('period', 'daily')
        ->set('referenceDate', '2026-08-15');

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
        ->set('period', 'daily')
        ->set('referenceDate', '2026-08-15')
        ->assertSee('Embroidery Work')
        ->assertSee('American Eagle — Style 6856', false);
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
        ->set('period', 'daily')
        ->set('referenceDate', '2026-08-15')
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
