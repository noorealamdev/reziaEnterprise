<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/daily-summary')->assertRedirect('/login');
});

test('selecting a company lists its distinct days with entry counts and totals', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 500,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-16',
        'bill_amount' => 700,
    ]);

    $this->actingAs($user);

    Volt::test('daily-summary.daily-summary-list')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('15 Aug 2026')
        ->assertSee('1,500.00')
        ->assertSee('16 Aug 2026')
        ->assertSee('700.00');
});

test('year and month filters narrow the listed days', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2025-08-15',
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-03-10',
        'bill_amount' => 700,
    ]);

    $this->actingAs($user);

    $byYear = Volt::test('daily-summary.daily-summary-list')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2025');
    expect($byYear->viewData('days'))->toHaveCount(1);

    $byMonth = Volt::test('daily-summary.daily-summary-list')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3');
    expect($byMonth->viewData('days'))->toHaveCount(1);

    $wrongMonth = Volt::test('daily-summary.daily-summary-list')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2026')
        ->set('monthFilter', '4');
    expect($wrongMonth->viewData('days'))->toHaveCount(0);
});

test('changing the year clears an incompatible month selection', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('daily-summary.daily-summary-list')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3')
        ->set('yearFilter', '2025')
        ->assertSet('monthFilter', '');
});

test('the daily detail page groups entries by category with subtotals and a grand total', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $labour = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 45,
        'cost_rate' => 11.5,
        'bill_rate' => 30,
        'cost_amount' => 517.5,
        'bill_amount' => 1350,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
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
        'service_category_id' => $tiffin->id,
        'entry_date' => '2026-08-16',
        'bill_amount' => 999,
    ]);

    $this->actingAs($user);

    $this->get(route('daily-summary.show', ['company' => $company->id, 'date' => '2026-08-15']))
        ->assertOk()
        ->assertSee('Egg')
        ->assertSee('Daily Basic Labour')
        ->assertDontSee('999.00')
        ->assertSee('7,850.00');
});

test('a tiffin departments items collapse into one row instead of one per item', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    // Only Egg bills — Banana is cost-tracking only, matching how a real
    // Tiffin batch is saved.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 200,
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 35,
        'bill_rate' => 30,
        'bill_amount' => 1050,
    ]);

    $this->actingAs($user);

    $this->get(route('daily-summary.show', ['company' => $company->id, 'date' => '2026-08-15']))
        ->assertOk()
        ->assertSee('Swing')
        // Quantity is Egg's own headcount (1,050 / 30), not a sum across
        // every ingredient.
        ->assertSee('35.00')
        ->assertSee('1,050.00')
        // Item names are named beside the department, not as separate rows
        ->assertSee('Banana, Egg', false);
});

test('a tiffin departments cost rate blends every item, but bill rate is the billing items own rate', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    // Only Egg bills. Cost rate is legitimately a blend of every item's
    // cost per headcount — but bill rate must show the client's actual
    // agreed rate (Egg's own bill_rate), never a blend across ingredients
    // with mismatched quantities.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 200,
        'cost_rate' => 5,
        'bill_rate' => 0,
        'cost_amount' => 1000,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 200,
        'cost_rate' => 15,
        'bill_rate' => 18,
        'cost_amount' => 3000,
        'bill_amount' => 3600,
    ]);

    $this->actingAs($user);

    $this->get(route('daily-summary.show', ['company' => $company->id, 'date' => '2026-08-15']))
        ->assertOk()
        // Blended cost rate: (1000 + 3000) / 200 headcount = 20.00
        ->assertSee('20.00')
        // Bill rate is Egg's own configured rate, not a blend: 18.00
        ->assertSee('18.00');
});

test('quantity and rate are based on the billing item only, not summed across every ingredient', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    // Only Egg bills — Banana/Bread are cost-only, and Banana's own
    // quantity is smaller than the day's real headcount (2500) because
    // part of it was covered by an exchange item.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-20',
        'supply_type' => 'Banana',
        'quantity' => 1500,
        'cost_rate' => 5,
        'bill_rate' => 0,
        'cost_amount' => 7500,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-20',
        'supply_type' => 'Biscuit',
        'quantity' => 1000,
        'cost_rate' => 8,
        'bill_rate' => 0,
        'cost_amount' => 8000,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-20',
        'supply_type' => 'Egg',
        'quantity' => 2500,
        'cost_rate' => 11.5,
        'bill_rate' => 30,
        'cost_amount' => 28750,
        'bill_amount' => 75000,
    ]);

    $this->actingAs($user);

    $this->get(route('daily-summary.show', ['company' => $company->id, 'date' => '2026-08-20']))
        ->assertOk()
        // Quantity is Egg's 2500 (the day's headcount), not 5000 (the sum
        // of Banana + Biscuit + Egg).
        ->assertSee('2,500.00')
        // Bill per person: 75,000 / 2,500 = 30.00 — the real per-person
        // rate, not diluted by summing every ingredient's quantity in.
        ->assertSee('30.00');
});
