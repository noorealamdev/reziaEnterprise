<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\LoadingUnloadingItem;
use App\Models\RolePermission;
use App\Models\TiffinDepartment;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/job-entries')->assertRedirect('/login');
});

test('list renders a seeded entry and filters by company, category and month', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Company A']);
    $companyB = Company::factory()->create(['name' => 'Company B']);
    $categoryA = makeServiceCategory('Daily Basic Labour');
    $categoryB = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $companyA->id,
        'service_category_id' => $categoryA->id,
        'supply_type' => 'Daily Basic Labour',
        'entry_date' => '2026-08-15',
    ]);
    JobEntry::factory()->create([
        'company_id' => $companyB->id,
        'service_category_id' => $categoryB->id,
        'supply_type' => 'Diesel',
        'entry_date' => '2026-07-15',
    ]);

    // Company/category names also appear as filter-dropdown <option> text
    // regardless of the active filter (by design, so users can switch
    // filters) — assert on the formatted entry date instead, which only
    // ever appears inside an actual entry card, never in a dropdown.
    $this->actingAs($user)
        ->get('/job-entries')
        ->assertOk()
        ->assertSeeVolt('job-entries.job-entry-list')
        ->assertSee('15 Aug 2026')
        ->assertSee('15 Jul 2026');

    Volt::test('job-entries.job-entry-list')
        ->set('companyFilter', (string) $companyA->id)
        ->assertSee('15 Aug 2026')
        ->assertDontSee('15 Jul 2026');

    Volt::test('job-entries.job-entry-list')
        ->set('categoryFilter', (string) $categoryB->id)
        ->assertSee('15 Jul 2026')
        ->assertDontSee('15 Aug 2026');

    Volt::test('job-entries.job-entry-list')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '8')
        ->assertSee('15 Aug 2026')
        ->assertDontSee('15 Jul 2026');
});

test('a job entrys remarks is shown on its row in the list', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'remarks' => 'Client asked for an extra worker on this shift',
    ]);

    $this->actingAs($user)
        ->get('/job-entries')
        ->assertOk()
        ->assertSee('Client asked for an extra worker on this shift');
});

test('a tiffin batchs shared remarks is shown once under its department, not per item', function () {
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
        'remarks' => 'Extra batch requested for a visiting buyer',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'remarks' => 'Extra batch requested for a visiting buyer',
    ]);

    $html = $this->actingAs($user)
        ->get('/job-entries')
        ->assertOk()
        ->assertSee('Extra batch requested for a visiting buyer')
        ->getContent();

    expect(substr_count($html, 'Extra batch requested for a visiting buyer'))->toBe(1);
});

test('a loading unloading batchs items collapse into one card, with shared remarks shown once', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Bosa Gari',
        'unit_label' => 'Cover Van',
        'quantity' => 3,
        'bill_amount' => 2100,
        'remarks' => 'shipment cost 3000/-',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Daily Labour',
        'unit_label' => 'Person',
        'quantity' => 10,
        'bill_amount' => 6000,
        'remarks' => 'shipment cost 3000/-',
    ]);

    $html = $this->actingAs($user)
        ->get('/job-entries')
        ->assertOk()
        ->assertSee('Bosa Gari')
        ->assertSee('Daily Labour')
        // Grand total of the card sums both items: 2,100 + 6,000.
        ->assertSee('8,100.00')
        ->getContent();

    expect(substr_count($html, 'shipment cost 3000/-'))->toBe(1);
});

test('a single loading unloading entry on its own still groups as a plain row, not a card', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');

    $entry = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Bosa Gari',
        'remarks' => 'Only one item today',
    ]);

    $this->actingAs($user);

    $groupedEntries = Volt::test('job-entries.job-entry-list')->viewData('groupedEntries');
    $rowGroups = $groupedEntries->get('2026-08-15');

    // One card for the day, and that card holds exactly the one entry —
    // it was never merged into a multi-item batch card.
    expect($rowGroups)->toHaveCount(1);
    expect($rowGroups->first())->toHaveCount(1);
    expect($rowGroups->first()->first()->id)->toBe($entry->id);
});

test('year filter alone finds every entry in that year, and clears the month when changed', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2025-08-15',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-01-10',
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-list')
        ->set('yearFilter', '2025')
        ->assertSee('15 Aug 2025')
        ->assertDontSee('10 Jan 2026');

    Volt::test('job-entries.job-entry-list')
        ->set('yearFilter', '2025')
        ->set('monthFilter', '8')
        ->set('yearFilter', '2026')
        ->assertSet('monthFilter', '');
});

test('pagination never splits a single date across two pages', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    // A busy day with far more rows than the page size, plus nine other
    // distinct dates — ten distinct dates in total, exactly one page's
    // worth, so the busy day's 15 rows must all still land on page 1
    // together rather than being cut off at a fixed row count.
    JobEntry::factory()->count(15)->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-09-10',
    ]);

    foreach (range(1, 9) as $day) {
        JobEntry::factory()->create([
            'company_id' => $company->id,
            'service_category_id' => $category->id,
            'entry_date' => "2026-09-0{$day}",
        ]);
    }

    // An 11th, older distinct date — pushed to page 2.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-20',
    ]);

    $this->actingAs($user);

    $page1 = Volt::test('job-entries.job-entry-list');
    expect($page1->viewData('groupedEntries'))->toHaveCount(10);
    expect($page1->viewData('groupedEntries')['2026-09-10']->flatten(1))->toHaveCount(15);
    $page1->assertSee('10 Sep 2026')->assertDontSee('20 Aug 2026');

    $page2 = $page1->call('gotoPage', 2);
    expect($page2->viewData('groupedEntries'))->toHaveCount(1);
    $page2->assertSee('20 Aug 2026')->assertDontSee('10 Sep 2026');
});

test('creating a simple entry persists with correct cost, bill and profit amounts', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('entry_date', '2026-08-20')
        ->set('supply_type', 'Daily Basic Labour')
        ->set('quantity', '10')
        ->set('cost_rate', '600')
        ->set('bill_rate', '700')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('job-entries.index'));

    $this->assertDatabaseHas('job_entries', [
        'company_id' => $company->id,
        'supply_type' => 'Daily Basic Labour',
        'cost_amount' => 6000,
        'bill_amount' => 7000,
        'profit_amount' => 1000,
    ]);
});

test('challan no. can be set on any category, not just Diesel Oil Supply', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('entry_date', '2026-08-20')
        ->set('supply_type', 'Daily Basic Labour')
        ->set('cost_amount', '10')
        ->set('bill_amount', '15')
        ->set('challan_no', ' 598 ')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'company_id' => $company->id,
        'challan_no' => '598',
    ]);
});

test('challan no. is not wiped when the service category is changed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('challan_no', '598')
        ->set('service_category_id', 1)
        ->assertSet('challan_no', '598');
});

test('a tiffin batchs challan no. is applied to every entry created and shown once on its card', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);
    makeTiffinRecipe($swing, ['Banana']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set('challan_no', '712')
        ->set('batchQuantities.'.$swing->id.'.Banana', '100')
        ->set('batchCostRates.'.$swing->id.'.Banana', '5')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'company_id' => $company->id,
        'tiffin_department_id' => $swing->id,
        'challan_no' => '712',
    ]);

    Volt::test('job-entries.job-entry-list')
        ->assertSee('Challan 712');
});

test('rate auto-fill populates cost and bill rate from the most recent job entry', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');
    $company->serviceCategories()->attach($category);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Diesel',
        'tiffin_department_id' => null,
        'buyer' => null,
        'cost_rate' => 95,
        'bill_rate' => 110,
        'entry_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('supply_type', 'Diesel')
        ->call('attemptRateAutoFill')
        ->assertSet('cost_rate', '95.00')
        ->assertSet('bill_rate', '110.00');
});

test('rate auto-fill uses the most recent entry when rates changed over time', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');
    $company->serviceCategories()->attach($category);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Diesel',
        'tiffin_department_id' => null,
        'buyer' => null,
        'cost_rate' => 90,
        'bill_rate' => 100,
        'entry_date' => now()->subDays(10)->toDateString(),
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Diesel',
        'tiffin_department_id' => null,
        'buyer' => null,
        'cost_rate' => 95,
        'bill_rate' => 110,
        'entry_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('supply_type', 'Diesel')
        ->call('attemptRateAutoFill')
        ->assertSet('cost_rate', '95.00')
        ->assertSet('bill_rate', '110.00');
});

test('tiffin department is required for Tiffin category', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Tiffin');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('entry_date', now()->toDateString())
        ->set('supply_type', 'Milk')
        ->set('cost_amount', '10')
        ->set('bill_amount', '15')
        ->call('save')
        ->assertHasErrors(['tiffin_department_id']);
});

test('selecting Loading Unloading shows every active item at once', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);
    LoadingUnloadingItem::create(['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1]);
    LoadingUnloadingItem::create(['name' => 'Daily Labour', 'unit_label' => 'Person', 'sort_order' => 2]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $category->id)
        ->assertSet('loadingUnloadingBatchMode', true)
        ->assertSee('Big')
        ->assertSee('Daily Labour');
});

test('filling in two Loading Unloading items on one submission creates entries for both', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);
    $big = LoadingUnloadingItem::create(['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1]);
    $labour = LoadingUnloadingItem::create(['name' => 'Daily Labour', 'unit_label' => 'Person', 'sort_order' => 2]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $category->id)
        ->set("batchLUQuantities.{$big->name}", '18')
        ->set("batchLUCostRates.{$big->name}", '700')
        ->set("batchLUBillRates.{$big->name}", '850')
        ->set("batchLUQuantities.{$labour->name}", '4')
        ->set("batchLUCostRates.{$labour->name}", '500')
        ->set("batchLUBillRates.{$labour->name}", '600')
        ->call('saveLoadingUnloadingBatch')
        ->assertHasNoErrors()
        ->assertRedirect(route('job-entries.index'));

    $this->assertDatabaseCount('job_entries', 2);
    $this->assertDatabaseHas('job_entries', [
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Big',
        'unit_label' => 'Cover Van',
        'quantity' => 18,
        'cost_amount' => 12600,
        'bill_amount' => 15300,
    ]);
    $this->assertDatabaseHas('job_entries', [
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Daily Labour',
        'unit_label' => 'Person',
        'quantity' => 4,
        'cost_amount' => 2000,
        'bill_amount' => 2400,
    ]);
});

test('leaving a Loading Unloading item blank skips it instead of requiring it', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);
    $big = LoadingUnloadingItem::create(['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1]);
    LoadingUnloadingItem::create(['name' => 'Small', 'unit_label' => 'Cover Van', 'sort_order' => 2]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $category->id)
        ->set("batchLUQuantities.{$big->name}", '10')
        ->set("batchLUCostRates.{$big->name}", '700')
        ->set("batchLUBillRates.{$big->name}", '850')
        ->call('saveLoadingUnloadingBatch')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('job_entries', 1);
    $this->assertDatabaseHas('job_entries', ['supply_type' => 'Big']);
    $this->assertDatabaseMissing('job_entries', ['supply_type' => 'Small']);
});

test('the loading unloading batch requires cost and bill rate once an item is touched', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);
    $big = LoadingUnloadingItem::create(['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $category->id)
        ->set("batchLUQuantities.{$big->name}", '10')
        ->call('saveLoadingUnloadingBatch')
        ->assertHasErrors(["batchLUCostRates.{$big->name}", "batchLUBillRates.{$big->name}"]);

    $this->assertDatabaseCount('job_entries', 0);
});

test('submitting with every Loading Unloading item left blank shows an error', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);
    LoadingUnloadingItem::create(['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $category->id)
        ->call('saveLoadingUnloadingBatch')
        ->assertHasErrors(['batchLUQuantities']);

    $this->assertDatabaseCount('job_entries', 0);
});

test('an inactive Loading Unloading item is not offered in the batch form', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);
    LoadingUnloadingItem::create(['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1]);
    LoadingUnloadingItem::create(['name' => 'Retired Item', 'unit_label' => 'Set', 'is_active' => false, 'sort_order' => 2]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $category->id)
        ->assertSee('Big')
        ->assertDontSee('Retired Item');
});

test('no active Loading Unloading items blocks the create form with a guidance message', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $category->id)
        ->assertSee('No Loading Unloading items are configured yet');
});

test('editing a legacy Loading Unloading entry still uses the single-entry form with an optional floor', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Loading Unloading');
    $company->serviceCategories()->attach($category);
    LoadingUnloadingItem::create(['name' => 'Big', 'unit_label' => 'Cover Van', 'sort_order' => 1]);

    $entry = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Old Style Entry',
        'quantity' => 5,
        'cost_rate' => 100,
        'bill_rate' => 150,
        'cost_amount' => 500,
        'bill_amount' => 750,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form', ['jobEntry' => $entry])
        ->assertSet('loadingUnloadingBatchMode', false)
        ->set('quantity', '6')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $entry->fresh()->quantity)->toBe(6.0);
});

test('buyer and style are required for Embroidery and Print category', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Embroidery & Print');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('entry_date', now()->toDateString())
        ->set('supply_type', 'Embroidery Work')
        ->set('cost_amount', '10')
        ->set('bill_amount', '15')
        ->call('save')
        ->assertHasErrors(['buyer', 'style']);
});

test('challan number is not required for Diesel category', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('entry_date', now()->toDateString())
        ->set('supply_type', 'Diesel')
        ->set('cost_amount', '95')
        ->set('bill_amount', '110')
        ->call('save')
        ->assertHasNoErrors();
});

test('ETP Eid Holiday saves directly-entered cost and bill amounts without a quantity', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('ETP Eid Holiday');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('entry_date', now()->toDateString())
        ->set('supply_type', 'ETP Tank Cleaning')
        ->set('company_adv_payment', '50000')
        ->set('cost_amount', '80000')
        ->set('bill_amount', '100000')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'company_id' => $company->id,
        'supply_type' => 'ETP Tank Cleaning',
        'quantity' => null,
        'cost_amount' => 80000,
        'bill_amount' => 100000,
        'company_adv_payment' => 50000,
    ]);
});

test('switching to ETP Eid Holiday clears a quantity already typed for a different category', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');
    $etpEid = makeServiceCategory('ETP Eid Holiday');
    $company->serviceCategories()->attach([$labour->id, $etpEid->id]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $labour->id)
        ->set('quantity', '10')
        ->set('service_category_id', $etpEid->id)
        ->assertSet('quantity', null);
});

test('ETP Eid Holiday cost and bill amounts are not overwritten by a quantity/rate calculation', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('ETP Eid Holiday');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    // Cost/Bill Rate stay available as free-standing fields (per the
    // client's choice), but must never drive Cost/Bill Amount here the
    // way they do for every other category, since there's no quantity to
    // multiply them by.
    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('cost_amount', '80000')
        ->set('bill_amount', '100000')
        ->set('cost_rate', '5')
        ->set('bill_rate', '7')
        ->assertSet('cost_amount', '80000')
        ->assertSet('bill_amount', '100000');
});

test('selecting Tiffin shows every department the company is assigned at once', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $washWorker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach([$swing->id, $washWorker->id]);

    makeTiffinRecipe($swing, ['Banana', 'Egg', 'Bread']);
    makeTiffinRecipe($washWorker, ['Banana', 'Egg', 'Bread']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->assertSet('multiItemMode', true)
        ->assertSee('Swing')
        ->assertSee('Wash Worker');
});

test('filling in two Tiffin departments on one submission creates entries for both', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $washWorker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach([$swing->id, $washWorker->id]);

    makeTiffinRecipe($swing, ['Banana', 'Egg']);
    makeTiffinRecipe($washWorker, ['Banana', 'Egg']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Banana", '200')
        ->set("batchCostRates.{$swing->id}.Banana", '5')
        ->set("batchBillRates.{$swing->id}.Banana", '6')
        ->set("batchQuantities.{$swing->id}.Egg", '205')
        ->set("batchCostRates.{$swing->id}.Egg", '11.5')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        ->set("batchQuantities.{$washWorker->id}.Banana", '80')
        ->set("batchCostRates.{$washWorker->id}.Banana", '5')
        ->set("batchBillRates.{$washWorker->id}.Banana", '6')
        ->set("batchQuantities.{$washWorker->id}.Egg", '90')
        ->set("batchCostRates.{$washWorker->id}.Egg", '11.5')
        ->set("batchBillRates.{$washWorker->id}.Egg", '30')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors()
        ->assertRedirect(route('job-entries.index'));

    $this->assertDatabaseCount('job_entries', 4);
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $swing->id, 'supply_type' => 'Banana', 'quantity' => 200]);
    // The +5 egg buffer is sent once for the whole company, not once per
    // department — Swing sorts first alphabetically, so it absorbs the
    // buffer (205 headcount + 5 = 210); Wash Worker's own Egg row stores
    // exactly its headcount (90), no buffer added a second time.
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $swing->id, 'supply_type' => 'Egg', 'quantity' => 210, 'bill_amount' => 6150]);
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $washWorker->id, 'supply_type' => 'Banana', 'quantity' => 80]);
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $washWorker->id, 'supply_type' => 'Egg', 'quantity' => 90, 'bill_amount' => 2700]);
});

test('the tiffin batch form shows the buffer note only on the department that carries it', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $washWorker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $tiffin = makeServiceCategory('Tiffin');
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach([$swing->id, $washWorker->id]);

    makeTiffinRecipe($swing, ['Egg']);
    makeTiffinRecipe($washWorker, ['Egg']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Egg", '200')
        ->set("batchQuantities.{$washWorker->id}.Egg", '80')
        ->assertSee('once for the whole company')
        ->assertSee('Buffer already added under Swing');
});

test('leaving a department entirely blank skips it instead of blocking the other departments', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $washWorker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach([$swing->id, $washWorker->id]);

    makeTiffinRecipe($swing, ['Banana', 'Egg']);
    makeTiffinRecipe($washWorker, ['Banana', 'Egg']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Banana", '200')
        ->set("batchCostRates.{$swing->id}.Banana", '5')
        ->set("batchBillRates.{$swing->id}.Banana", '6')
        ->set("batchQuantities.{$swing->id}.Egg", '205')
        ->set("batchCostRates.{$swing->id}.Egg", '11.5')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        // Wash Worker left entirely blank
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors()
        ->assertRedirect(route('job-entries.index'));

    $this->assertDatabaseCount('job_entries', 2);
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $swing->id, 'supply_type' => 'Banana']);
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $swing->id, 'supply_type' => 'Egg']);
});

test('the tiffin batch requires a quantity and rate for every item in a department once its touched', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana', 'Egg']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Banana", '200')
        ->set("batchCostRates.{$swing->id}.Banana", '5')
        ->set("batchBillRates.{$swing->id}.Banana", '6')
        ->call('saveTiffinItemBatch')
        ->assertHasErrors([
            "batchQuantities.{$swing->id}.Egg",
            "batchCostRates.{$swing->id}.Egg",
            "batchBillRates.{$swing->id}.Egg",
        ]);

    $this->assertDatabaseCount('job_entries', 0);
});

test('submitting with every department left blank shows an error', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->call('saveTiffinItemBatch')
        ->assertHasErrors(['batchQuantities']);

    $this->assertDatabaseCount('job_entries', 0);
});

test('tiffin batch rates auto-fill per item and per department from the most recent job entry', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana', 'Egg']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'buyer' => null,
        'cost_rate' => 11.5,
        'bill_rate' => 30,
        'entry_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->assertSet("batchCostRates.{$swing->id}.Egg", '11.50')
        ->assertSet("batchBillRates.{$swing->id}.Egg", '30.00')
        ->assertSet("batchCostRates.{$swing->id}.Banana", '');
});

test('tiffin bill rate auto-fills from the company\'s fixed rate, not history, when one is set', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['tiffin_bill_rate' => 30]);
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana', 'Egg']);

    // A stale historical rate that should be ignored now that the company
    // has its own fixed rate configured.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'buyer' => null,
        'cost_rate' => 11.5,
        'bill_rate' => 25,
        'entry_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->assertSet("batchBillRates.{$swing->id}.Egg", '30.00');
});

test('a full tiffin batch save computes bill_amount from Egg quantity times the company rate', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['tiffin_bill_rate' => 30]);
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana', 'Egg', 'Bread']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Banana", '2500')
        ->set("batchCostRates.{$swing->id}.Banana", '5')
        ->set("batchQuantities.{$swing->id}.Egg", '2500')
        ->set("batchCostRates.{$swing->id}.Egg", '11.5')
        ->set("batchQuantities.{$swing->id}.Bread", '2500')
        ->set("batchCostRates.{$swing->id}.Bread", '7.8')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'bill_rate' => 30,
        'bill_amount' => 75000,
    ]);
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $swing->id, 'supply_type' => 'Banana', 'bill_amount' => 0]);
    $this->assertDatabaseHas('job_entries', ['tiffin_department_id' => $swing->id, 'supply_type' => 'Bread', 'bill_amount' => 0]);
});

test('list filters by item and billed status', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Daily Basic Labour',
        'entry_date' => '2026-08-15',
    ]);

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-FILTER-1',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'supply_type' => 'Local Sand Supply',
        'entry_date' => '2026-07-10',
        'invoice_id' => $invoice->id,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-list')
        ->set('itemFilter', 'Daily Basic Labour')
        ->assertSee('15 Aug 2026')
        ->assertDontSee('10 Jul 2026');

    Volt::test('job-entries.job-entry-list')
        ->set('statusFilter', 'unbilled')
        ->assertSee('15 Aug 2026')
        ->assertDontSee('10 Jul 2026');

    Volt::test('job-entries.job-entry-list')
        ->set('statusFilter', 'billed')
        ->assertSee('10 Jul 2026')
        ->assertDontSee('15 Aug 2026');
});

test('a matching purchase locks the created tiffin entry cost rate, even if a different value is posted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Egg']);
    $egg = TiffinItem::where('name', 'Egg')->firstOrFail();

    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => now()->toDateString(),
        'quantity' => 500,
        'cost_rate' => 12,
        'cost_amount' => 6000,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Egg", '200')
        ->set("batchCostRates.{$swing->id}.Egg", '99')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    // Headcount 200 + the fixed egg buffer (5) = 205 eggs actually costed.
    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'quantity' => 205,
        'cost_rate' => 12,
        'cost_amount' => 2460,
    ]);
});

test('without a matching purchase, tiffin cost rate stays exactly as typed', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Egg']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Egg", '200')
        ->set("batchCostRates.{$swing->id}.Egg", '9.5')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'cost_rate' => 9.5,
    ]);
});

test('with no purchase on the exact date, the most recent earlier purchase still locks the cost rate', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Egg']);
    $egg = TiffinItem::where('name', 'Egg')->firstOrFail();

    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => now()->subDays(3)->toDateString(),
        'quantity' => 500,
        'cost_rate' => 12,
        'cost_amount' => 6000,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Egg", '200')
        ->set("batchCostRates.{$swing->id}.Egg", '99')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'cost_rate' => 12,
    ]);
});

test('an exchange item is saved as its own cost-only row alongside a reduced Banana quantity', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana', 'Egg']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Banana", '1500')
        ->set("batchCostRates.{$swing->id}.Banana", '5')
        ->set("batchExchangeItemNames.{$swing->id}", 'Biscuit')
        ->set("batchExchangeQuantities.{$swing->id}", '1000')
        ->set("batchExchangeCostRates.{$swing->id}", '8')
        ->set("batchQuantities.{$swing->id}.Egg", '2500')
        ->set("batchCostRates.{$swing->id}.Egg", '11.5')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('job_entries', 3);
    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Banana',
        'quantity' => 1500,
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Biscuit',
        'quantity' => 1000,
        'cost_rate' => 8,
        'cost_amount' => 8000,
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    // Headcount 2500 + the fixed egg buffer (5) = 2505 eggs actually
    // costed, but bill_amount is still headcount × rate (never inflated
    // by the buffer).
    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'quantity' => 2505,
        'bill_rate' => 30,
        'bill_amount' => 75000,
    ]);
});

test('an exchange item named the same as a catalog item in that department is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana', 'Egg', 'Bread']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Banana", '1500')
        ->set("batchCostRates.{$swing->id}.Banana", '5')
        ->set("batchExchangeItemNames.{$swing->id}", 'Bread')
        ->set("batchExchangeQuantities.{$swing->id}", '1000')
        ->set("batchExchangeCostRates.{$swing->id}", '8')
        ->set("batchQuantities.{$swing->id}.Egg", '2500')
        ->set("batchCostRates.{$swing->id}.Egg", '11.5')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        ->set("batchQuantities.{$swing->id}.Bread", '2500')
        ->set("batchCostRates.{$swing->id}.Bread", '7.8')
        ->call('saveTiffinItemBatch')
        ->assertHasErrors(["batchExchangeItemNames.{$swing->id}"]);

    $this->assertDatabaseCount('job_entries', 0);
});

test('the exchange item fields only appear once, not per item', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);

    makeTiffinRecipe($swing, ['Banana', 'Egg', 'Bread']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->assertSeeHtml("batch_exchange_name_{$swing->id}")
        ->assertSeeHtml("batch_exchange_qty_{$swing->id}")
        ->assertSeeHtml("batch_exchange_cost_{$swing->id}");
});

test('the tiffin batch edit form also locks cost rate from a matching purchase', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $tiffin = makeServiceCategory('Tiffin');
    $egg = TiffinItem::firstOrCreate(['name' => 'Egg']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 40,
        'cost_rate' => 5,
        'bill_rate' => 30,
    ]);

    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-08-15',
        'quantity' => 500,
        'cost_rate' => 13,
        'cost_amount' => 6500,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $company, 'department' => $swing, 'date' => '2026-08-15'])
        ->assertSet('costRates.Egg', '13.00')
        ->set('costRates.Egg', '999')
        ->set('quantities.Egg', '50')
        ->call('save')
        ->assertHasNoErrors();

    // Headcount 50 + the fixed egg buffer (5) = 55 eggs actually costed.
    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'cost_rate' => 13,
        'quantity' => 55,
        'cost_amount' => 715,
    ]);
});

test('the tiffin batch edit form only shows a Bill Rate field for the Egg row', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $tiffin = makeServiceCategory('Tiffin');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 40,
        'bill_rate' => 30,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 40,
        'bill_rate' => 0,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $company, 'department' => $swing, 'date' => '2026-08-15'])
        ->assertSeeHtml('bill_Egg')
        ->assertDontSeeHtml('bill_Banana');
});

test('editing a non-buffer-carrying department does not add the egg buffer a second time', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $washWorker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $tiffin = makeServiceCategory('Tiffin');

    // Swing sorts first alphabetically, so it carries the company's single
    // +5 buffer (205 headcount + 5); Wash Worker's own Egg row stores
    // exactly its headcount (90) — matching how a multi-department batch
    // is now saved.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 210,
        'bill_rate' => 30,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $washWorker->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 90,
        'bill_rate' => 30,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $company, 'department' => $washWorker, 'date' => '2026-08-15'])
        ->assertSet('carriesEggBuffer', false)
        ->assertSet('quantities.Egg', '90.00')
        ->assertSee('Buffer already added under Swing')
        ->set('quantities.Egg', '100')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $washWorker->id,
        'supply_type' => 'Egg',
        'quantity' => 100,
    ]);
    // Swing's own row is untouched — still carries the buffer alone.
    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'quantity' => 210,
    ]);
});

test('editing the buffer-carrying department reverse-computes headcount correctly even with a sibling department present', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $washWorker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $tiffin = makeServiceCategory('Tiffin');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 210,
        'bill_rate' => 30,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $washWorker->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 90,
        'bill_rate' => 30,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $company, 'department' => $swing, 'date' => '2026-08-15'])
        ->assertSet('carriesEggBuffer', true)
        ->assertSet('quantities.Egg', '205.00')
        ->set('quantities.Egg', '250')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Egg',
        'quantity' => 255,
    ]);
});

test('in-charge is typed freely, not selected from a fixed user list', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('entry_date', now()->toDateString())
        ->set('supply_type', 'Daily Basic Labour')
        ->set('cost_amount', '10')
        ->set('bill_amount', '15')
        ->set('in_charge', ' Mr. Karim ')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('job_entries', [
        'company_id' => $company->id,
        'in_charge' => 'Mr. Karim',
    ]);
});

test('editing a job entry updates its attributes', function () {
    $user = User::factory()->create();
    $jobEntry = JobEntry::factory()->create(['supply_type' => 'Old Item']);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form', ['jobEntry' => $jobEntry])
        ->set('supply_type', 'New Item')
        ->set('cost_amount', (string) $jobEntry->cost_amount)
        ->set('bill_amount', (string) $jobEntry->bill_amount)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('job-entries.index'));

    expect($jobEntry->fresh()->supply_type)->toBe('New Item');
});

test('deleting a job entry removes it', function () {
    $user = User::factory()->create();
    $jobEntry = JobEntry::factory()->create();

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-list')
        ->call('confirmDelete', $jobEntry->id)
        ->call('delete');

    $this->assertDatabaseMissing('job_entries', ['id' => $jobEntry->id]);
});

test('a billed job entry cannot be edited', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => makeServiceCategory('Daily Basic Labour')->id,
        'invoice_number' => 'TEST-BILLED-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    $jobEntry = JobEntry::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-form', ['jobEntry' => $jobEntry])
        ->assertRedirect(route('job-entries.index'));
});

test('a billed job entry cannot be deleted via the list', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => makeServiceCategory('Daily Basic Labour')->id,
        'invoice_number' => 'TEST-BILLED-2',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    $jobEntry = JobEntry::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    $this->actingAs($user);

    Volt::test('job-entries.job-entry-list')
        ->call('confirmDelete', $jobEntry->id)
        ->call('delete');

    $this->assertDatabaseHas('job_entries', ['id' => $jobEntry->id]);
});

test('the tiffin batch edit form loads all items for that department and day pre-filled', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $tiffin = makeServiceCategory('Tiffin');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 40,
        'cost_rate' => 5,
        'bill_rate' => 6,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 45,
        'cost_rate' => 11.5,
        'bill_rate' => 30,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $company, 'department' => $swing, 'date' => '2026-08-15'])
        ->assertSet('quantities.Banana', '40.00')
        // Stored quantity 45 minus the fixed egg buffer (5) = headcount 40.
        ->assertSet('quantities.Egg', '40.00')
        ->assertSet('billRates.Egg', '30.00');
});

test('saving the tiffin batch edit form updates every item together', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $tiffin = makeServiceCategory('Tiffin');

    $banana = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 40,
        'cost_rate' => 5,
        'bill_rate' => 6,
        'cost_amount' => 200,
        'bill_amount' => 240,
    ]);
    $egg = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 45,
        'cost_rate' => 11.5,
        'bill_rate' => 30,
        'cost_amount' => 517.5,
        'bill_amount' => 1350,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $company, 'department' => $swing, 'date' => '2026-08-15'])
        ->set('quantities.Banana', '50')
        ->set('quantities.Egg', '55')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('job-entries.index'));

    // Banana never bills — only Egg carries the fixed per-person rate.
    expect((float) $banana->fresh()->quantity)->toBe(50.0);
    expect((float) $banana->fresh()->bill_rate)->toBe(0.0);
    expect((float) $banana->fresh()->bill_amount)->toBe(0.0);

    // Headcount 55 + the fixed egg buffer (5) = 60 eggs actually costed,
    // but bill_amount stays headcount × rate (55 × 30), never the
    // buffered count × rate.
    expect((float) $egg->fresh()->quantity)->toBe(60.0);
    expect((float) $egg->fresh()->bill_amount)->toBe(1650.0);
});

test('the tiffin batch edit form is blocked once any item in it is billed', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $tiffin = makeServiceCategory('Tiffin');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_number' => 'TEST-BATCH-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'invoice_id' => $invoice->id,
    ]);

    $this->actingAs($user);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $company, 'department' => $swing, 'date' => '2026-08-15'])
        ->assertRedirect(route('job-entries.index'));
});

test('profit_amount recomputes via the model save hook outside the Livewire form', function () {
    $jobEntry = JobEntry::factory()->create([
        'cost_amount' => 100,
        'bill_amount' => 150,
    ]);

    expect((float) $jobEntry->fresh()->profit_amount)->toBe(50.0);

    $jobEntry->update(['bill_amount' => 200]);

    expect((float) $jobEntry->fresh()->profit_amount)->toBe(100.0);
});

test('an accountant can create a job entry but gets a 403 trying to edit one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::JobEntriesCreate->value]);
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $jobEntry = JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id]);

    $this->actingAs($accountant);

    // Create is allowed — the seeded/granted job_entries.create permission.
    $this->get(route('job-entries.create'))->assertOk();

    // Modify is not — no job_entries.modify grant.
    $this->get(route('job-entries.edit', $jobEntry))->assertForbidden();

    Volt::test('job-entries.job-entry-list')
        ->call('confirmDelete', $jobEntry->id)
        ->call('delete')
        ->assertForbidden();
});
