<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/bill-statement')->assertRedirect('/login');
});

test('screen pagination never drops a row from the printed statement or the totals', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    // More than one screen page's worth (15 per page) — one JobEntry per
    // month, since pending rows group by company+category+month, not by
    // individual entry.
    foreach (range(1, 20) as $i) {
        JobEntry::factory()->create([
            'company_id' => $company->id,
            'service_category_id' => $category->id,
            'entry_date' => now()->startOfMonth()->subMonths($i)->toDateString(),
            'bill_amount' => 100,
        ]);
    }

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id);

    // $rows itself (what the print block renders from) always holds every
    // filtered row regardless of the on-screen page — the printed
    // document must show all 20, not just one page's worth.
    expect($component->viewData('rows'))->toHaveCount(20);
    expect($component->viewData('totalBilled'))->toBe(2000.0);

    // Rows past the first page are still in the HTML (present for print),
    // just hidden on screen — confirms nothing was silently dropped from
    // the DOM entirely.
    $component->assertSeeHtml('print:!table-row');
});

test('rows are ordered most recent period first', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    // Deliberately created oldest-first, so a naive "insertion order"
    // result would fail this test just as easily as the old
    // oldest-period-first sort would.
    foreach ([5, 1, 3] as $monthsAgo) {
        JobEntry::factory()->create([
            'company_id' => $company->id,
            'service_category_id' => $category->id,
            'entry_date' => now()->startOfMonth()->subMonths($monthsAgo)->toDateString(),
            'bill_amount' => 100,
        ]);
    }

    $this->actingAs($user);

    $rows = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->viewData('rows');

    expect($rows)->toHaveCount(3);
    expect($rows->pluck('period_start')->map(fn ($date) => $date->format('Y-m'))->values()->all())->toBe([
        now()->startOfMonth()->subMonths(1)->format('Y-m'),
        now()->startOfMonth()->subMonths(3)->format('Y-m'),
        now()->startOfMonth()->subMonths(5)->format('Y-m'),
    ]);
});

test('year, month and status filters narrow the rows and their totals', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-YR-1',
        'period_start' => '2025-08-01',
        'period_end' => '2025-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2025-08-10',
        'bill_amount' => 500,
    ]);
    // A different year, still unbilled.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-03-10',
        'bill_amount' => 700,
    ]);

    $this->actingAs($user);

    // Year alone finds both the billed 2025 row and nothing from 2026.
    $byYear = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2025');
    expect($byYear->viewData('rows'))->toHaveCount(1);
    expect($byYear->viewData('totalBilled'))->toBe(500.0);

    // Year + month narrows to exactly that month.
    $byMonth = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3');
    expect($byMonth->viewData('rows'))->toHaveCount(1);
    expect($byMonth->viewData('totalBilled'))->toBe(700.0);

    // Status: Billed only finds the invoiced 2025 row.
    $billedOnly = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('statusFilter', 'billed');
    expect($billedOnly->viewData('rows'))->toHaveCount(1);
    expect($billedOnly->viewData('totalBilled'))->toBe(500.0);

    // Status: Unbilled only finds the pending 2026 row.
    $unbilledOnly = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('statusFilter', 'unbilled');
    expect($unbilledOnly->viewData('rows'))->toHaveCount(1);
    expect($unbilledOnly->viewData('totalBilled'))->toBe(700.0);
});

test('status filter narrows to paid, unpaid, or signed invoices — matching the Dashboard Invoice Overview links', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $paidInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'STATUS-PAID',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'status' => 'paid',
        'paid_at' => '2026-01-15',
    ]);
    $dueSignedInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'STATUS-DUE-SIGNED',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'status' => 'due',
        'signed_copy_path' => 'signed-invoices/status-due-signed.pdf',
    ]);
    $partialInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'STATUS-PARTIAL',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'status' => 'partial',
    ]);

    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => '2026-01-10', 'bill_amount' => 1000, 'invoice_id' => $paidInvoice->id]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => '2026-02-10', 'bill_amount' => 500, 'invoice_id' => $dueSignedInvoice->id]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'entry_date' => '2026-03-10', 'bill_amount' => 300, 'invoice_id' => $partialInvoice->id]);

    $this->actingAs($user);

    $paidOnly = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('statusFilter', 'paid');
    expect($paidOnly->viewData('rows'))->toHaveCount(1);
    expect($paidOnly->viewData('totalBilled'))->toBe(1000.0);

    $unpaidOnly = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('statusFilter', 'unpaid');
    expect($unpaidOnly->viewData('rows'))->toHaveCount(2);
    expect($unpaidOnly->viewData('totalBilled'))->toBe(800.0);

    $signedOnly = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('statusFilter', 'signed');
    expect($signedOnly->viewData('rows'))->toHaveCount(1);
    expect($signedOnly->viewData('totalBilled'))->toBe(500.0);
});

test('the year dropdown always offers every year present, even while a year is selected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2024-01-05',
        'bill_amount' => 100,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-01-05',
        'bill_amount' => 200,
    ]);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2026');

    expect($component->viewData('availableYears')->all())->toBe([2026, 2024]);
});

test('changing the year clears an incompatible month selection', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3')
        ->set('yearFilter', '2025')
        ->assertSet('monthFilter', '');
});

test('selecting a company lists its invoices with amount, status and running totals', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $paidInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-202606',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'status' => 'paid',
        'paid_at' => '2026-07-05',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $paidInvoice->id, 'bill_amount' => 45200]);

    $dueInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-202607',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $dueInvoice->id, 'bill_amount' => 38432]);

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('AAL-202606')
        ->assertSee('AAL-202607')
        ->assertSee('45,200.00')
        ->assertSee('38,432.00')
        // Total Billed
        ->assertSee('83,632.00')
        // Total Paid
        ->assertSee(number_format(45200, 2))
        // Total Outstanding
        ->assertSee(number_format(38432, 2));
});

test('with no company filter, invoices from every company are listed together', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Ananta Apparels Ltd']);
    $otherCompany = Company::factory()->create(['name' => 'Fashion Point Ltd']);
    $category = makeServiceCategory('Daily Basic Labour');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-202608',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $otherInvoice = Invoice::create([
        'company_id' => $otherCompany->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'FPL-202608',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'paid',
        'paid_at' => '2026-09-01',
    ]);
    JobEntry::factory()->create(['company_id' => $otherCompany->id, 'service_category_id' => $category->id, 'invoice_id' => $otherInvoice->id, 'bill_amount' => 2000]);

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->assertSee('AAL-202608')
        ->assertSee('FPL-202608')
        ->assertSee('Ananta Apparels Ltd')
        ->assertSee('Fashion Point Ltd')
        // Total Billed across both companies
        ->assertSee('3,000.00');
});

test('an invoice from another company never appears in this companys statement', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-202608',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $otherInvoice = Invoice::create([
        'company_id' => $otherCompany->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'OFL-202608',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['company_id' => $otherCompany->id, 'service_category_id' => $category->id, 'invoice_id' => $otherInvoice->id, 'bill_amount' => 9999]);

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('AAL-202608')
        ->assertDontSee('OFL-202608');
});

test('unbilled job entries appear as a Not Invoiced row without needing an invoice generated first', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-05',
        'bill_amount' => 700,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-20',
        'bill_amount' => 300,
    ]);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('Not Invoiced')
        ->assertSee('1,000.00');

    expect($component->viewData('totalBilled'))->toBe(1000.0);
    expect($component->viewData('totalPaid'))->toBe(0.0);
    expect($component->viewData('totalOutstanding'))->toBe(1000.0);
});

test('a pending Tiffin month bills only for Egg, not Banana/Bread which never carry a bill amount', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');

    // Only Egg bills — Banana/Bread are cost-tracking rows with
    // bill_amount always 0, exactly as the Tiffin batch form now saves
    // them. If this regressed back to summing every item's bill_amount,
    // this statement would silently overbill the factory.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'entry_date' => '2026-08-10',
        'supply_type' => 'Banana',
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'entry_date' => '2026-08-10',
        'supply_type' => 'Bread',
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'entry_date' => '2026-08-10',
        'supply_type' => 'Egg',
        'quantity' => 2500,
        'bill_rate' => 30,
        'bill_amount' => 75000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('75,000.00');

    expect($component->viewData('totalBilled'))->toBe(75000.0);
    expect($component->viewData('totalOutstanding'))->toBe(75000.0);
});

test('a generated Tiffin invoice sums only Egg\'s bill_amount, not every ingredient row', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_number' => 'TEST-BS-TIF',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-10',
        'supply_type' => 'Banana',
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-10',
        'supply_type' => 'Egg',
        'quantity' => 2500,
        'bill_rate' => 30,
        'bill_amount' => 75000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('75,000.00');

    expect($component->viewData('totalBilled'))->toBe(75000.0);
    expect($component->viewData('totalOutstanding'))->toBe(75000.0);
});

test('a pending months Generate Invoice link pre-fills the company, category and period', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-05',
        'bill_amount' => 700,
    ]);

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee(route('invoices.create', ['company' => $company->id, 'category' => $category->id, 'period' => '2026-08']));
});

test('two categories with unbilled entries the same month show as two separate pending rows', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $diesel = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'entry_date' => '2026-08-05',
        'bill_amount' => 700,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $diesel->id,
        'entry_date' => '2026-08-05',
        'bill_amount' => 300,
    ]);

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('Tiffin')
        ->assertSee('Diesel Oil Supply')
        ->assertSee('700.00')
        ->assertSee('300.00');
});

test('an already-invoiced month does not also appear as pending', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-202608',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('AAL-202608')
        ->assertDontSee('Not Invoiced');
});

test('a paid invoice shows zero balance due while a due invoice shows its full amount', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-202608',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'paid',
        'paid_at' => '2026-09-01',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 5000]);
    $invoice->payments()->create(['amount' => 5000, 'paid_on' => '2026-09-01']);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id);

    expect($component->viewData('totalOutstanding'))->toBe(0.0);
    expect($component->viewData('totalPaid'))->toBe(5000.0);
});

test('a partially paid invoice shows Partially Paid and the remaining balance', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-PARTIAL',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'partial',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);
    $invoice->payments()->create(['amount' => 400, 'paid_on' => '2026-08-15']);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->assertSee('Partially Paid')
        // Balance Due: 1000 - 400
        ->assertSee('600.00');

    expect($component->viewData('totalPaid'))->toBe(400.0);
    expect($component->viewData('totalOutstanding'))->toBe(600.0);
});

test('balance due accounts for an advance already paid against a due invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('ETP Eid Holiday');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-ETP-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_id' => $invoice->id,
        'bill_amount' => 100000,
        'company_adv_payment' => 40000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        // Amount billed is still the full 100,000
        ->assertSee('100,000.00')
        // But the balance still owed is only 60,000 after the advance
        ->assertSee('60,000.00');

    expect($component->viewData('totalOutstanding'))->toBe(60000.0);
});

test('Total Paid includes advance payments, so Billed always reconciles to Paid + Outstanding', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('ETP Eid Holiday');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-ETP-2',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_id' => $invoice->id,
        'bill_amount' => 100000,
        'company_adv_payment' => 50000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id);

    $billed = $component->viewData('totalBilled');
    $paid = $component->viewData('totalPaid');
    $outstanding = $component->viewData('totalOutstanding');

    // The advance is real money already received — it must count in
    // Total Paid, not just reduce Balance Due, or Billed − Paid stops
    // matching Outstanding (which is exactly what an accountant checking
    // the totals by hand would notice).
    expect($paid)->toBe(50000.0);
    expect($outstanding)->toBe(50000.0);
    expect($billed - $paid)->toBe($outstanding);
});

test('search finds a bill by invoice number and never matches an un-invoiced row', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $matching = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-DBL-0925-01',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 500,
        'invoice_id' => $matching->id,
    ]);

    $other = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-DBL-0825-01',
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subMonth()->toDateString(),
        'bill_amount' => 300,
        'invoice_id' => $other->id,
    ]);

    // A pending (never-invoiced) row must never match a search, since it
    // has no invoice number/id yet.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 999,
    ]);

    $this->actingAs($user);

    $byNumber = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('search', '0925-01');

    expect($byNumber->viewData('rows'))->toHaveCount(1);
    expect($byNumber->viewData('rows')->first()->invoice->id)->toBe($matching->id);

    $caseInsensitive = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('search', 'aal-dbl-0925');

    expect($caseInsensitive->viewData('rows'))->toHaveCount(1);
    expect($caseInsensitive->viewData('rows')->first()->invoice->id)->toBe($matching->id);
});

test('a row flags whether its bill has a signed copy on file, independent of payment status', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $signedButUnpaid = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-DBL-SIGNED-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
        'signed_copy_path' => 'signed-bills/test.jpg',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 500,
        'invoice_id' => $signedButUnpaid->id,
    ]);

    $unsigned = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-DBL-UNSIGNED-1',
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subMonth()->toDateString(),
        'bill_amount' => 300,
        'invoice_id' => $unsigned->id,
    ]);

    $this->actingAs($user);

    $rows = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->viewData('rows')
        ->keyBy(fn ($row) => $row->invoice->id);

    expect($rows[$signedButUnpaid->id]->hasSignedCopy)->toBeTrue();
    expect($rows[$signedButUnpaid->id]->status)->toBe('due');
    expect($rows[$unsigned->id]->hasSignedCopy)->toBeFalse();
});

test('the Signed badge is hidden once a bill is fully paid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $paidAndSigned = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-DBL-PAID-1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'signed_copy_path' => 'signed-bills/test.jpg',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 500,
        'invoice_id' => $paidAndSigned->id,
    ]);

    $dueAndSigned = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-DBL-DUE-1',
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'status' => 'due',
        'signed_copy_path' => 'signed-bills/test2.jpg',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subMonth()->toDateString(),
        'bill_amount' => 300,
        'invoice_id' => $dueAndSigned->id,
    ]);

    $this->actingAs($user);

    // The underlying flag stays true either way — it's the badge that's
    // conditionally hidden once paid, not the fact itself.
    $component = Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id);

    $rows = $component->viewData('rows')->keyBy(fn ($row) => $row->invoice->id);
    expect($rows[$paidAndSigned->id]->hasSignedCopy)->toBeTrue();

    $component->assertSeeHtml('Signed');
    $component->assertSee('AAL-DBL-DUE-1');

    // A paid, signed-only company should render no "Signed" badge at all —
    // "Signed" itself still appears as a status filter option, so this
    // checks for the badge's own title text, not the bare word.
    Volt::test('bill-statement.bill-statement')
        ->set('companyFilter', (string) $company->id)
        ->set('statusFilter', 'billed')
        ->set('search', 'PAID-1')
        ->assertDontSee('Sent to the factory and signed');
});

test('the service category filter narrows the rows to just that category', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $labour = makeServiceCategory('Daily Basic Labour');
    $diesel = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $labour->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 500,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $diesel->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 300,
    ]);

    $this->actingAs($user);

    $component = Volt::test('bill-statement.bill-statement')
        ->set('categoryFilter', (string) $labour->id);

    $rows = $component->viewData('rows');
    expect($rows)->toHaveCount(1);
    expect($rows->first()->category->id)->toBe($labour->id);
});

test('selecting a single service category hides the redundant Category column', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 500,
    ]);

    $this->actingAs($user);

    Volt::test('bill-statement.bill-statement')
        ->set('categoryFilter', (string) $category->id)
        ->assertDontSeeHtml('>Category<');
});
