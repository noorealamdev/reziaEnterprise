<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use App\Models\User;
use Livewire\Volt\Volt;

test('choosing a month on the last day of a longer month still bills the chosen month', function () {
    $this->travelTo('2026-10-31 12:00:00');
    $this->actingAs(User::factory()->create());
    $company = Company::factory()->create(['code' => 'AAL']);
    $category = makeServiceCategory('Daily Basic Labour');
    $september = JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => '2026-09-15']);
    $october = JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => '2026-10-15']);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-09')
        ->set('invoice_number', 'RE/AAL/DBL/1/092026')
        ->call('generate')
        ->assertHasNoErrors();

    $invoice = Invoice::sole();
    expect($invoice->period_start->toDateString())->toBe('2026-09-01');
    expect($invoice->period_end->toDateString())->toBe('2026-09-30');
    expect($september->fresh()->invoice_id)->toBe($invoice->id);
    expect($october->fresh()->invoice_id)->toBeNull();
});

function progressCompany(): Company
{
    return Company::factory()->create(['code' => 'PAL', 'name' => 'Progress Apparels Ltd.']);
}

function progressEntry(Company $company, int $categoryId, string $date, float $amount = 100, ?int $departmentId = null): JobEntry
{
    return JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $categoryId,
        'tiffin_department_id' => $departmentId,
        'entry_date' => $date,
        'bill_amount' => $amount,
    ]);
}

test('Progress opens on the 25 to 25 cycle holding its oldest unbilled entry', function (string $oldestEntry, string $from, string $to) {
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Combustible Material');
    progressEntry($company, $category->id, $oldestEntry);
    progressEntry($company, $category->id, '2027-03-10');

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->assertSet('periodType', 'custom')
        ->assertSet('billFrom', $from)
        ->assertSet('billTo', $to);
})->with([
    'early in the month' => ['2026-09-01', '2026-08-25', '2026-09-25'],
    'on the 25th' => ['2026-10-25', '2026-09-25', '2026-10-25'],
    'after the 25th' => ['2026-10-26', '2026-10-25', '2026-11-25'],
    'crossing a new year' => ['2026-12-28', '2026-12-25', '2027-01-25'],
    'on the 31st' => ['2026-10-31', '2026-10-25', '2026-11-25'],
]);

test('with no unbilled entries Progress opens on the cycle holding today', function () {
    $this->travelTo('2026-11-01 12:00:00');
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Combustible Material');

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->assertSet('billFrom', '2026-10-25')
        ->assertSet('billTo', '2026-11-25');
});

test('a Progress bill takes only entries inside its dates and shows those dates', function () {
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Combustible Material');
    $before = progressEntry($company, $category->id, '2026-09-24', 1);
    $firstDay = progressEntry($company, $category->id, '2026-09-25', 10);
    $lastDay = progressEntry($company, $category->id, '2026-10-25', 100);
    $after = progressEntry($company, $category->id, '2026-10-26', 1000);

    $form = Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('billFrom', '2026-09-25')
        ->set('billTo', '2026-10-25')
        ->set('invoice_number', 'RE/PAL/CM/1/102026');

    expect($form->viewData('preview')['count'])->toBe(2);
    expect((float) $form->viewData('preview')['total'])->toBe(110.0);

    $form->call('generate')->assertHasNoErrors();

    $invoice = Invoice::sole();
    expect($invoice->period_start->toDateString())->toBe('2026-09-25');
    expect($invoice->period_end->toDateString())->toBe('2026-10-25');
    expect($firstDay->fresh()->invoice_id)->toBe($invoice->id);
    expect($lastDay->fresh()->invoice_id)->toBe($invoice->id);
    expect($before->fresh()->invoice_id)->toBeNull();
    expect($after->fresh()->invoice_id)->toBeNull();

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice->fresh()])
        ->assertSee('Period Of 25-Sep-2026 to 25-Oct-2026.');
});

test('an entry on the 25th shared by two cycles is only ever billed once', function () {
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Combustible Material');
    $shared = progressEntry($company, $category->id, '2026-10-25', 100);
    $next = progressEntry($company, $category->id, '2026-11-05', 50);

    foreach ([['2026-09-25', '2026-10-25', 'RE/PAL/CM/1/102026'], ['2026-10-25', '2026-11-25', 'RE/PAL/CM/2/112026']] as [$from, $to, $number]) {
        Volt::test('invoices.invoice-generate-form')
            ->set('company_id', $company->id)
            ->set('service_category_id', $category->id)
            ->set('billFrom', $from)
            ->set('billTo', $to)
            ->set('invoice_number', $number)
            ->call('generate')
            ->assertHasNoErrors();
    }

    $first = Invoice::where('invoice_number', 'RE/PAL/CM/1/102026')->sole();
    $second = Invoice::where('invoice_number', 'RE/PAL/CM/2/112026')->sole();
    expect($shared->fresh()->invoice_id)->toBe($first->id);
    expect($next->fresh()->invoice_id)->toBe($second->id);
    expect((float) $second->jobEntries()->sum('bill_amount'))->toBe(50.0);
});

test('Progress Tiffin still bills one department at a time inside the dates', function () {
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $wash = TiffinDepartment::create(['name' => 'Wash Worker']);
    $company->tiffinDepartments()->attach([$swing->id, $wash->id]);
    $swingEntry = progressEntry($company, $category->id, '2026-09-10', 100, $swing->id);
    $washEntry = progressEntry($company, $category->id, '2026-09-10', 200, $wash->id);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('tiffin_department_id', $swing->id)
        ->assertSet('billFrom', '2026-08-25')
        ->assertSet('billTo', '2026-09-25')
        ->set('invoice_number', 'RE/PAL/T/1/092026')
        ->call('generate')
        ->assertHasNoErrors();

    expect($swingEntry->fresh()->invoice_id)->toBe(Invoice::sole()->id);
    expect($washEntry->fresh()->invoice_id)->toBeNull();
});

test('Progress can still bill a whole calendar month, with the month wording', function () {
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Combustible Material');
    $september = progressEntry($company, $category->id, '2026-09-30');
    $october = progressEntry($company, $category->id, '2026-10-01');

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('periodType', 'month')
        ->set('period', '2026-09')
        ->set('invoice_number', 'RE/PAL/CM/1/092026')
        ->call('generate')
        ->assertHasNoErrors();

    $invoice = Invoice::sole();
    expect($september->fresh()->invoice_id)->toBe($invoice->id);
    expect($october->fresh()->invoice_id)->toBeNull();
    Volt::test('invoices.invoice-detail', ['invoice' => $invoice->fresh()])
        ->assertSee('Month Of September 2026.');
});

test('invalid Progress dates or dates with no entries create no bill', function (string $from, string $to, string $errorField) {
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Combustible Material');
    progressEntry($company, $category->id, '2026-09-10');

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('billFrom', $from)
        ->set('billTo', $to)
        ->set('invoice_number', 'RE/PAL/CM/1/092026')
        ->call('generate')
        ->assertHasErrors([$errorField]);

    expect(Invoice::count())->toBe(0);
})->with([
    'To before From' => ['2026-09-25', '2026-08-25', 'billTo'],
    'empty From' => ['', '2026-09-25', 'billFrom'],
    'empty To' => ['2026-08-25', '', 'billTo'],
    'impossible date' => ['2026-02-31', '2026-03-25', 'billFrom'],
    'no entries' => ['2025-01-25', '2025-02-25', 'billFrom'],
]);

test('other companies never get custom dates, even if the request asks for them', function () {
    $this->actingAs(User::factory()->create());
    $company = Company::factory()->create(['code' => 'AAL']);
    $category = makeServiceCategory('Daily Basic Labour');
    $inMonth = JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => '2026-09-10']);
    $outside = JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => '2026-08-30']);

    $form = Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->assertSet('periodType', 'month')
        ->assertDontSee('Custom Dates')
        ->set('periodType', 'custom')
        ->set('billFrom', '2026-08-25')
        ->set('billTo', '2026-09-25')
        ->set('period', '2026-09')
        ->set('invoice_number', 'RE/AAL/DBL/1/092026')
        ->call('generate')
        ->assertHasNoErrors();

    $invoice = Invoice::sole();
    expect($invoice->period_start->toDateString())->toBe('2026-09-01');
    expect($inMonth->fresh()->invoice_id)->toBe($invoice->id);
    expect($outside->fresh()->invoice_id)->toBeNull();
});

test('a past paper bill keeps its month wording, even with one date in both period columns', function () {
    $invoice = new Invoice(['manual_amount' => 500, 'period_start' => '2026-03-14', 'period_end' => '2026-03-14']);

    expect($invoice->isCustomPeriod)->toBeFalse();
    expect($invoice->periodLabel)->toBe('March 2026');
});

test('the Bill Statement lists a 25 to 25 bill under the month it ends in, with its dates', function () {
    $this->actingAs(User::factory()->create());
    $company = progressCompany();
    $category = makeServiceCategory('Combustible Material');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'RE/PAL/CM/1/102026',
        'period_start' => '2026-09-25',
        'period_end' => '2026-10-25',
        'status' => 'due',
    ]);
    progressEntry($company, $category->id, '2026-10-01')->update(['invoice_id' => $invoice->id]);

    Volt::test('bill-statement.bill-statement')
        ->set('monthFilter', '10')
        ->assertSee('RE/PAL/CM/1/102026')
        ->assertSee('25-Sep-2026 to 25-Oct-2026');
    Volt::test('bill-statement.bill-statement')
        ->set('monthFilter', '9')
        ->assertDontSee('RE/PAL/CM/1/102026');
});
