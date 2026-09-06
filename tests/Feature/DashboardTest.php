<?php

use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\RolePermission;
use App\Models\TiffinDepartment;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('dashboard shows unbilled total and ready-to-invoice companies grouped correctly', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Ananta Apparels Ltd']);
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subDays(2)->toDateString(),
        'bill_amount' => 1000,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subDays(3)->toDateString(),
        'bill_amount' => 500,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Ananta Apparels Ltd')
        ->assertSee('1,500.00')
        ->assertSee('2 entries unbilled');
});

test('dashboard excludes already-invoiced entries from the unbilled total', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subDay()->toDateString(),
        'bill_amount' => 2000,
        'invoice_id' => $invoice->id,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee("You're all caught up", false);
});

test('ready to invoice shows two separate rows when a company has unbilled entries in two categories', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Ananta Apparels Ltd']);
    $tiffin = makeServiceCategory('Tiffin');
    $diesel = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'entry_date' => now()->subDay()->toDateString(),
        'bill_amount' => 700,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $diesel->id,
        'entry_date' => now()->subDay()->toDateString(),
        'bill_amount' => 300,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Tiffin')
        ->assertSee('Diesel Oil Supply')
        ->assertSee('700.00')
        ->assertSee('300.00');
});

test('dashboard outstanding total sums only due invoices', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $dueInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'DUE-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);
    $paidInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'PAID-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'invoice_id' => $dueInvoice->id, 'bill_amount' => 750]);
    JobEntry::factory()->create(['company_id' => $company->id, 'invoice_id' => $paidInvoice->id, 'bill_amount' => 9999]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('750.00');
});

test("dashboard lists today's activity across companies", function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Fashion Point Ltd']);
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'supply_type' => 'Labour Supply',
        'bill_amount' => 300,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subDay()->toDateString(),
        'bill_amount' => 400,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Fashion Point Ltd')
        ->assertSee('Labour Supply')
        ->assertDontSee('Nothing logged today yet');
});

test("today's activity collapses a tiffin department's items into one row", function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Ananta Apparels Ltd']);
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => now()->toDateString(),
        'supply_type' => 'Banana',
        'bill_amount' => 150,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => now()->toDateString(),
        'supply_type' => 'Egg',
        'bill_amount' => 900,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Swing')
        ->assertSee('1,050.00')
        // Item names are named beside the department, not as separate rows
        ->assertSee('Banana, Egg', false);
});

test('dashboard shows billed total across all invoiced entries', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'BILL-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 4000,
        'invoice_id' => $invoice->id,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 1500,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('4,000.00')
        ->assertSee('1,500.00');
});

test('billed vs unbilled trend chart splits this month total by invoice status', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TREND-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 6000,
        'invoice_id' => $invoice->id,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 2500,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(now()->format('M Y').' — Billed 6,000.00 · Unbilled 2,500.00', false);
});

test('by company breakdown shows the largest companies first and truncates the rest', function () {
    $user = User::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    foreach (range(1, 9) as $i) {
        $company = Company::factory()->create(['name' => "Breakdown Co {$i}"]);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'service_category_id' => $category->id,
            'invoice_number' => "BRK-{$i}",
            'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => now()->subMonths(2)->endOfMonth()->toDateString(),
            'status' => 'due',
        ]);
        JobEntry::factory()->create([
            'company_id' => $company->id,
            'service_category_id' => $category->id,
            'entry_date' => now()->subMonths(2)->toDateString(),
            'bill_amount' => $i * 1000,
            'invoice_id' => $invoice->id,
        ]);
    }

    $this->actingAs($user);

    $names = Volt::test('dashboard.dashboard')
        ->viewData('companyBreakdown')['rows']
        ->pluck('company.name');

    expect($names->all())->toBe(['Breakdown Co 9', 'Breakdown Co 8', 'Breakdown Co 7', 'Breakdown Co 6', 'Breakdown Co 5', 'Breakdown Co 4', 'Breakdown Co 3', 'Breakdown Co 2']);
    expect($names)->not->toContain('Breakdown Co 1');

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('+1 more company not shown');
});

test('dashboard shows company totals and empty states with no data', function () {
    $user = User::factory()->create();

    Company::factory()->count(2)->create(['is_active' => true]);
    Company::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('3 companies total')
        ->assertSee('2 active')
        ->assertSee('Nothing logged today yet')
        ->assertSee("You're all caught up", false);
});

test('profit only counts paid invoices, while billed and cost total every entry regardless of payment', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $paidInvoiceA = Invoice::create([
        'company_id' => $companyA->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'PROFIT-PAID-A',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);
    $paidInvoiceB = Invoice::create([
        'company_id' => $companyB->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'PROFIT-PAID-B',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);
    $dueInvoice = Invoice::create([
        'company_id' => $companyA->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'PROFIT-DUE-A',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $companyA->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 5000,
        'cost_amount' => 3000,
        'invoice_id' => $paidInvoiceA->id,
    ]);
    JobEntry::factory()->create([
        'company_id' => $companyB->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 2000,
        'cost_amount' => 500,
        'invoice_id' => $paidInvoiceB->id,
    ]);
    // Billed and costed, but not yet paid — must count toward Total Billed
    // and Total Cost (work was done, cost was incurred) but never toward
    // Total Profit or Margin until the invoice is actually paid.
    JobEntry::factory()->create([
        'company_id' => $companyA->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 9999,
        'cost_amount' => 1,
        'invoice_id' => $dueInvoice->id,
    ]);

    $this->actingAs($user);

    $component = Volt::test('dashboard.dashboard');

    expect($component->viewData('profitTotalBilled'))->toBe(16999.0);
    expect($component->viewData('profitTotalCost'))->toBe(3501.0);
    expect($component->viewData('profitTotal'))->toBe(3500.0);
    expect($component->viewData('profitMargin'))->toBe(50.0);
});

test('profit filters narrow by year, month and company, and clear the month when the year changes', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $paidInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'PROFIT-FILTER',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'paid',
        'paid_at' => '2026-08-20',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
        'cost_amount' => 400,
        'invoice_id' => $paidInvoice->id,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-07-15',
        'bill_amount' => 9999,
        'cost_amount' => 1,
    ]);
    JobEntry::factory()->create([
        'company_id' => $otherCompany->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 9999,
        'cost_amount' => 1,
    ]);

    $this->actingAs($user);

    $component = Volt::test('dashboard.dashboard')
        ->set('profitCompanyFilter', (string) $company->id)
        ->set('profitYearFilter', '2026')
        ->set('profitMonthFilter', '8');

    expect($component->viewData('profitTotalBilled'))->toBe(1000.0);
    expect($component->viewData('profitTotal'))->toBe(600.0);

    $component->set('profitYearFilter', '2025');
    expect($component->get('profitMonthFilter'))->toBe('');
});

test('profit breakdown switches from companies to categories once a single company is selected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');
    $diesel = makeServiceCategory('Diesel Oil Supply');

    $labourInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'invoice_number' => 'PROFIT-CAT-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);
    $dieselInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $diesel->id,
        'invoice_number' => 'PROFIT-CAT-2',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 1000,
        'cost_amount' => 200,
        'invoice_id' => $labourInvoice->id,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $diesel->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 3000,
        'cost_amount' => 500,
        'invoice_id' => $dieselInvoice->id,
    ]);

    $this->actingAs($user);

    $component = Volt::test('dashboard.dashboard')
        ->set('profitCompanyFilter', (string) $company->id);

    $breakdown = $component->viewData('profitBreakdown');

    expect($breakdown['byCompany'])->toBeFalse();
    expect($breakdown['rows']->pluck('label')->all())->toBe(['Diesel Oil Supply', 'Daily Basic Labour']);
});

test('invoice overview breaks down paid, unpaid, signed, and not-invoiced amounts', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $paidInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'OVERVIEW-PAID',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);
    // Signed but still due — proves Signed is independent of payment status.
    $signedDueInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'OVERVIEW-SIGNED-DUE',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
        'signed_copy_path' => 'signed-invoices/overview-signed-due.pdf',
    ]);
    $partialInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'OVERVIEW-PARTIAL',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'partial',
    ]);

    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => now()->toDateString(), 'bill_amount' => 1000, 'invoice_id' => $paidInvoice->id]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => now()->toDateString(), 'bill_amount' => 500, 'invoice_id' => $signedDueInvoice->id]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => now()->toDateString(), 'bill_amount' => 300, 'invoice_id' => $partialInvoice->id]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => now()->toDateString(), 'bill_amount' => 700]);

    $this->actingAs($user);

    $rows = Volt::test('dashboard.dashboard')->viewData('invoiceOverview')->keyBy('label');

    expect($rows['Paid']['count'])->toBe(1);
    expect($rows['Paid']['amount'])->toBe(1000.0);
    expect($rows['Unpaid']['count'])->toBe(2);
    expect($rows['Unpaid']['amount'])->toBe(800.0);
    expect($rows['Signed']['count'])->toBe(1);
    expect($rows['Signed']['amount'])->toBe(500.0);
    expect($rows['Not Invoiced']['count'])->toBe(1);
    expect($rows['Not Invoiced']['amount'])->toBe(700.0);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Invoice Overview')
        ->assertSee('1,000.00')
        ->assertSee('800.00')
        ->assertSee('500.00')
        ->assertSee('700.00')
        ->assertSee('/bill-statement?status=paid', false)
        ->assertSee('/bill-statement?status=unpaid', false)
        ->assertSee('/bill-statement?status=signed', false)
        ->assertSee('/bill-statement?status=unbilled', false);
});

test('invoice overview cards are not clickable links for a user without Bill Statement access', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::DashboardView->value]);

    $this->actingAs($staff)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Invoice Overview')
        ->assertDontSee('/bill-statement?status=', false);
});

test('agreement deadlines list shows expiring and expired agreements but not distant ones', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Ananta Apparels Ltd']);

    $expiring = CompanyAgreement::factory()->create([
        'company_id' => $company->id,
        'title' => 'Expiring Soon Agreement',
        'end_date' => now()->addDays(10)->toDateString(),
    ]);
    $expired = CompanyAgreement::factory()->create([
        'company_id' => $company->id,
        'title' => 'Already Expired Agreement',
        'end_date' => now()->subDays(5)->toDateString(),
    ]);
    $distant = CompanyAgreement::factory()->create([
        'company_id' => $company->id,
        'title' => 'Far Away Agreement',
        'end_date' => now()->addDays(90)->toDateString(),
    ]);

    $this->actingAs($user);

    $rows = Volt::test('dashboard.dashboard')->viewData('agreementDeadlines')->pluck('agreement.id');

    expect($rows)->toContain($expiring->id, $expired->id);
    expect($rows)->not->toContain($distant->id);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Agreement Deadlines')
        ->assertSee('Expiring Soon Agreement')
        ->assertSee('Already Expired Agreement')
        ->assertDontSee('Far Away Agreement');
});

test('a user without company agreements permission does not see the agreement deadlines section', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::DashboardView->value]);

    CompanyAgreement::factory()->create(['end_date' => now()->addDays(5)->toDateString()]);

    $this->actingAs($staff)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Agreement Deadlines');
});

test('daily expense chart shows the window total across several days', function () {
    $user = User::factory()->create();

    Expense::factory()->create([
        'expense_date' => now()->toDateString(),
        'amount' => 500,
    ]);
    Expense::factory()->create([
        'expense_date' => now()->subDays(3)->toDateString(),
        'amount' => 250,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(now()->format('d M'))
        ->assertSee('Total: 750.00', false);
});

test('daily expense chart excludes expenses older than the trend window', function () {
    $user = User::factory()->create();

    Expense::factory()->create([
        'expense_date' => now()->subDays(20)->toDateString(),
        'amount' => 10000,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('No expenses recorded in this window.');
});

test('a user without expenses view permission does not see the daily expense chart', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::DashboardView->value]);

    Expense::factory()->create(['expense_date' => now()->toDateString(), 'amount' => 500]);

    $this->actingAs($staff)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Last 14 Days');
});
