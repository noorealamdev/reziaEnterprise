<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use App\Models\TiffinDepartment;
use App\Models\User;
use Database\Seeders\ServiceCategorySeeder;
use Database\Seeders\TiffinDepartmentSeeder;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/companies')->assertRedirect('/login');
});

test('companies index lists a seeded company', function () {
    $user = User::factory()->create();
    Company::factory()->create(['name' => 'Ananta Apparels Ltd', 'code' => 'AAL']);

    $this->actingAs($user)
        ->get('/companies')
        ->assertOk()
        ->assertSeeVolt('companies.company-list')
        ->assertSee('Ananta Apparels Ltd')
        ->assertSee('AAL');
});

test('company list search filters by name and code', function () {
    $user = User::factory()->create();
    Company::factory()->create(['name' => 'Ananta Apparels Ltd', 'code' => 'AAL']);
    Company::factory()->create(['name' => 'Other Factory Ltd', 'code' => 'OFL']);

    $this->actingAs($user);

    Volt::test('companies.company-list')
        ->set('search', 'Ananta')
        ->assertSee('Ananta Apparels Ltd')
        ->assertDontSee('Other Factory Ltd');
});

test('creating a company with valid data persists and redirects', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('companies.company-form')
        ->set('name', 'New Factory')
        ->set('code', 'nf1')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('companies.index'));

    $this->assertDatabaseHas('companies', ['code' => 'NF1', 'name' => 'New Factory']);
});

test('creating a company with a duplicate code fails validation', function () {
    $user = User::factory()->create();
    Company::factory()->create(['code' => 'AAL']);

    $this->actingAs($user);

    Volt::test('companies.company-form')
        ->set('name', 'Another Factory')
        ->set('code', 'AAL')
        ->call('save')
        ->assertHasErrors(['code']);
});

test('setting a tiffin bill rate on a company persists it', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('companies.company-form', ['company' => $company])
        ->set('tiffin_bill_rate', '30')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $company->fresh()->tiffin_bill_rate)->toBe(30.0);
});

test('tiffin bill rate rejects a negative value', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('companies.company-form', ['company' => $company])
        ->set('tiffin_bill_rate', '-5')
        ->call('save')
        ->assertHasErrors(['tiffin_bill_rate']);
});

test('editing a company updates its attributes', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('companies.company-form', ['company' => $company])
        ->set('name', 'Renamed Ltd')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('companies.index'));

    expect($company->fresh()->name)->toBe('Renamed Ltd');
});

test('editing a company keeps its own code valid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('companies.company-form', ['company' => $company])
        ->set('code', $company->code)
        ->call('save')
        ->assertHasNoErrors();
});

test('deleting a company removes it via the confirm flow', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('companies.company-list')
        ->call('confirmDelete', $company->id)
        ->call('delete');

    $this->assertDatabaseMissing('companies', ['id' => $company->id]);
});

test('service category and tiffin department toggles persist via pivot tables', function () {
    $this->seed(ServiceCategorySeeder::class);
    $this->seed(TiffinDepartmentSeeder::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    $tiffin = ServiceCategory::where('name', 'Tiffin')->firstOrFail();
    $swing = TiffinDepartment::where('name', 'Swing')->firstOrFail();

    Volt::test('companies.company-form')
        ->set('name', 'Toggle Co')
        ->set('code', 'TGL')
        ->set('serviceCategoryIds', [$tiffin->id])
        ->set('tiffinDepartmentIds', [$swing->id])
        ->call('save')
        ->assertHasNoErrors();

    $company = Company::where('code', 'TGL')->firstOrFail();

    expect($company->serviceCategories->pluck('id')->all())->toBe([$tiffin->id]);
    expect($company->tiffinDepartments->pluck('id')->all())->toBe([$swing->id]);
});

test('the company detail page shows totals scoped to that company only', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'bill_amount' => 500,
        'invoice_id' => null,
    ]);
    JobEntry::factory()->create([
        'company_id' => $otherCompany->id,
        'service_category_id' => $category->id,
        'bill_amount' => 9999,
    ]);

    $this->actingAs($user);

    Volt::test('companies.company-detail', ['company' => $company])
        ->assertSet('company.id', $company->id)
        ->assertSee('1,500.00')
        ->assertDontSee('9,999.00');
});

test('recent entries collapses a tiffin departments items into one row', function () {
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
        'bill_amount' => 150,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'bill_amount' => 900,
    ]);

    $this->actingAs($user);

    Volt::test('companies.company-detail', ['company' => $company])
        ->assertSee('Swing')
        ->assertSee('1,050.00')
        // Item names are named beside the department, not as separate rows
        ->assertSee('Banana, Egg', false);
});

test('visiting job entries create with a company query param pre-selects that company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)
        ->get('/job-entries/create?company='.$company->id)
        ->assertOk()
        ->assertSee('company_id&quot;:'.$company->id, false);
});

test('visiting invoice generate with a company query param pre-selects that company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)
        ->get('/invoices/create?company='.$company->id)
        ->assertOk()
        ->assertSee('company_id&quot;:'.$company->id, false);
});

test('visiting invoice generate with company and period query params pre-selects both', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)
        ->get('/invoices/create?company='.$company->id.'&period=2026-08')
        ->assertOk()
        ->assertSee('company_id&quot;:'.$company->id, false)
        ->assertSee('period&quot;:&quot;2026-08&quot;', false);
});

test('an invalid period query param is ignored instead of crashing the form', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)
        ->get('/invoices/create?company='.$company->id.'&period=not-a-date')
        ->assertOk()
        ->assertSee('period&quot;:&quot;&quot;', false);
});
