<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\RolePermission;
use App\Models\TiffinDepartment;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/invoices')->assertRedirect('/login');
});

test('generating an invoice pulls only unbilled entries within the selected month', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['code' => 'AAL']);
    $category = makeServiceCategory('Daily Basic Labour');

    $inMonth = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);
    $outsideMonth = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-07-15',
        'bill_amount' => 500,
    ]);
    $otherCompany = JobEntry::factory()->create([
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-16',
        'bill_amount' => 700,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->call('generate')
        ->assertHasNoErrors();

    $invoice = Invoice::first();
    expect($invoice)->not->toBeNull();
    expect($invoice->company_id)->toBe($company->id);
    expect($invoice->service_category_id)->toBe($category->id);
    expect($invoice->invoice_number)->toBe("AAL-{$category->invoice_code}-202608");
    expect($invoice->status)->toBe('due');
    expect($invoice->vat_percent)->toBeNull();

    expect($inMonth->fresh()->invoice_id)->toBe($invoice->id);
    expect($outsideMonth->fresh()->invoice_id)->toBeNull();
    expect($otherCompany->fresh()->invoice_id)->toBeNull();
});

test('entering a VAT rate stores it on the invoice, whatever the percentage', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->set('vatRate', '15')
        ->call('generate')
        ->assertHasNoErrors();

    expect((float) Invoice::first()->vat_percent)->toBe(15.0);
});

test('leaving the VAT rate blank stores no VAT on the invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->call('generate')
        ->assertHasNoErrors();

    expect(Invoice::first()->vat_percent)->toBeNull();
});

test('an out-of-range VAT rate is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->set('vatRate', '150')
        ->call('generate')
        ->assertHasErrors(['vatRate']);

    expect(Invoice::count())->toBe(0);
});

test('generating never pulls in a different categorys entries for the same company and month', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $diesel = makeServiceCategory('Diesel Oil Supply');

    $tiffinEntry = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);
    $dieselEntry = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $diesel->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 500,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $tiffin->id)
        ->set('period', '2026-08')
        ->call('generate')
        ->assertHasNoErrors();

    $invoice = Invoice::first();
    expect($invoice->jobEntries()->count())->toBe(1);
    expect($tiffinEntry->fresh()->invoice_id)->toBe($invoice->id);
    expect($dieselEntry->fresh()->invoice_id)->toBeNull();
});

test('an off-day entry is included in the invoice but contributes zero to the total', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['code' => 'AAL']);
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-10',
        'bill_amount' => 1000,
    ]);
    $offDay = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-11',
        'bill_amount' => 0,
        'cost_amount' => 0,
        'is_off_day' => true,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->call('generate')
        ->assertHasNoErrors();

    $invoice = Invoice::first();

    expect($offDay->fresh()->invoice_id)->toBe($invoice->id);
    expect((float) $invoice->jobEntries()->sum('bill_amount'))->toBe(1000.0);
});

test('generating with nothing unbilled shows an error and creates no invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $company->serviceCategories()->attach($category);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->call('generate')
        ->assertHasErrors(['period']);

    expect(Invoice::count())->toBe(0);
});

test('generating again for a period that already has an invoice only bills the new entries under a distinct invoice number', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['code' => 'AAL']);
    $category = makeServiceCategory('Daily Basic Labour');

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-05',
        'bill_amount' => 1000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->call('generate');

    $lateEntry = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-08-20',
        'bill_amount' => 300,
    ]);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $company->id)
        ->set('service_category_id', $category->id)
        ->set('period', '2026-08')
        ->call('generate')
        ->assertHasNoErrors();

    expect(Invoice::count())->toBe(2);
    $second = Invoice::orderByDesc('id')->first();
    expect($second->invoice_number)->toBe("AAL-{$category->invoice_code}-202608-2");
    expect($second->jobEntries()->count())->toBe(1);
    expect($lateEntry->fresh()->invoice_id)->toBe($second->id);
});

test('the invoice shows one row per day instead of one per item', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_number' => 'TEST-TIF',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'bill_amount' => 150,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'bill_amount' => 900,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->assertSee('Swing')
        ->assertSee('1,050.00')
        // Item names are named beside the department, not as separate rows
        ->assertSee('Banana, Egg', false);
});

test('the invoice combines every department for the same day into a single row', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $washWorker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_number' => 'TEST-TIF-COMBINE',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 125,
        'bill_amount' => 1990,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $washWorker->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 80,
        'bill_amount' => 1300,
    ]);

    $this->actingAs($user);

    $component = Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->assertSee('Swing, Wash Worker', false)
        ->assertSee('Banana, Egg', false)
        // Combined amount for the single row: 1990 + 1300
        ->assertSee('3,290.00');

    // Exactly one table row for the day, not one per department — check
    // via the computed rows rather than counting DOM occurrences of the
    // date, since the date also appears in "In Word"-adjacent text.
    expect($component->viewData('rows'))->toHaveCount(1);
});

test('the invoice quantity and rate are based on the billing item only, not every ingredient summed', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_number' => 'TEST-TIF-HEADCOUNT',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    // Only Egg bills; Banana's own quantity is short of the real headcount
    // because part of it was covered by an exchange item (Biscuit).
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 1500,
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Biscuit',
        'quantity' => 1000,
        'bill_rate' => 0,
        'bill_amount' => 0,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 2500,
        'bill_rate' => 30,
        'bill_amount' => 75000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('invoices.invoice-detail', ['invoice' => $invoice]);
    $row = $component->viewData('rows')->first();

    expect($row->quantity)->toBe(2500.0);
    expect($row->rate)->toBe(30.0);
    expect($row->billAmount)->toBe(75000.0);
});

test('the invoice rate is the companys configured tiffin rate, not distorted by eggs always-sent buffer', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['tiffin_bill_rate' => 30]);
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'invoice_number' => 'TEST-TIF-BUFFER',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    // Egg's *stored* quantity is headcount (42) plus the always-sent +5
    // buffer (47) — but only the 42-person headcount is billed, at the
    // company's configured 30.00 rate. Dividing bill_amount by the stored
    // (buffered) quantity would show a distorted rate (1,260 / 47 = 26.81)
    // instead of the real 30.00 the client agreed to.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 47,
        'bill_rate' => 30,
        'bill_amount' => 1260,
    ]);

    $this->actingAs($user);

    $component = Volt::test('invoices.invoice-detail', ['invoice' => $invoice]);
    $row = $component->viewData('rows')->first();

    expect($row->rate)->toBe(30.0);
    expect($row->quantity)->toBe(42.0);
    expect($row->billAmount)->toBe(1260.0);
});

test('the invoice document shows the amount spelled out in Bangladeshi lakh/crore words', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-WORDS',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 318552,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->assertSee('Three Lakh Eighteen Thousand Five Hundred Fifty Two Taka Only.');
});

test('a VAT invoice shows the VAT line and a grand total that includes it', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-VAT',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
        'vat_percent' => 10,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 1000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->assertSee('VAT (10%)', false)
        // Total 1000 + Vat 100 = 1100
        ->assertSee('100.00')
        ->assertSee('1,100.00');
});

test('an invoice with an advance payment shows the advance and due breakdown', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('ETP Eid Holiday');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-ADV',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_id' => $invoice->id,
        'entry_date' => '2026-08-15',
        'bill_amount' => 450000,
        'company_adv_payment' => 100000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->assertSee('Advance Paid')
        ->assertSee('100,000.00')
        ->assertSee('Due')
        // Due: 450,000 - 100,000
        ->assertSee('350,000.00');
});

test('recording a payment that covers the full amount marks the invoice paid', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->assertSet('paymentAmount', '1000')
        ->set('paymentType', 'check')
        ->set('checkNumber', '0451236')
        ->set('bankName', 'Dutch-Bangla Bank')
        ->set('paymentDescription', 'Handed over by the factory accountant')
        ->set('checkImage', UploadedFile::fake()->image('check.jpg'))
        ->call('recordPayment')
        ->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    expect($invoice->paid_at)->not->toBeNull();
    expect($invoice->payments)->toHaveCount(1);

    $payment = $invoice->payments->first();
    expect((float) $payment->amount)->toBe(1000.0);
    expect($payment->type)->toBe('check');
    expect($payment->check_number)->toBe('0451236');
    expect($payment->bank_name)->toBe('Dutch-Bangla Bank');
    expect($payment->description)->toBe('Handed over by the factory accountant');
    expect($payment->check_image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($payment->check_image_path);
});

test('a plain cash payment stores no check details even if some were typed in before switching type', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-CASH-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 500]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->assertSet('paymentType', 'cash')
        ->set('checkNumber', '9999')
        ->set('bankName', 'Some Bank')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $payment = $invoice->fresh()->payments->first();
    expect($payment->type)->toBe('cash');
    expect($payment->check_number)->toBeNull();
    expect($payment->bank_name)->toBeNull();
});

test('a check payment without any check number or bank name still records fine', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-CHECK-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 500]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->set('paymentType', 'check')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $payment = $invoice->fresh()->payments->first();
    expect($payment->type)->toBe('check');
    expect($payment->check_number)->toBeNull();
    expect($payment->bank_name)->toBeNull();
});

test('recording a partial payment marks the invoice partially paid and leaves a balance', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-PARTIAL',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->set('paymentAmount', '400')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertSee('Partially Paid')
        // Balance Due: 1000 - 400
        ->assertSee('600.00');

    expect($invoice->fresh()->status)->toBe('partial');
    expect($invoice->fresh()->paid_at)->toBeNull();
});

test('two partial payments that together cover the total mark the invoice fully paid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-TWO-PART',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')->set('paymentAmount', '400')->call('recordPayment');

    expect($invoice->fresh()->status)->toBe('partial');

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->assertSet('paymentAmount', '600')
        ->call('recordPayment');

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    expect($invoice->payments)->toHaveCount(2);
});

test('removing a payment recalculates the invoice back to due', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-REMOVE',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')->call('recordPayment');

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    $payment = $invoice->payments->first();

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('confirmDeletePayment', $payment->id)
        ->call('deletePayment');

    $invoice->refresh();
    expect($invoice->status)->toBe('due');
    expect($invoice->payments)->toHaveCount(0);
});

test('deleting a due invoice detaches its entries and removes the invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-2',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    $entry = JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('delete')
        ->assertRedirect(route('bill-statement.index', ['company' => $company->id]));

    $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    expect($entry->fresh()->invoice_id)->toBeNull();
});

test('deleting an invoice with a recorded payment is blocked', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-3',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'paid',
        'paid_at' => '2026-08-31',
    ]);
    $entry = JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);
    $invoice->payments()->create(['amount' => 1000, 'paid_on' => '2026-08-31']);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('delete');

    $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    expect($entry->fresh()->invoice_id)->toBe($invoice->id);
});

test('uploading a signed bill copy stores it against the invoice', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-SIGNED',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->set('signedCopy', UploadedFile::fake()->image('signed-bill.jpg'))
        ->call('uploadSignedCopy')
        ->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice->signed_copy_path)->not->toBeNull();
    Storage::disk('public')->assertExists($invoice->signed_copy_path);
});

test('the signed bill copy upload rejects a file that is not an image or pdf', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-SIGNED-REJECT',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->set('signedCopy', UploadedFile::fake()->create('bill.docx', 100))
        ->call('uploadSignedCopy')
        ->assertHasErrors(['signedCopy']);

    expect($invoice->fresh()->signed_copy_path)->toBeNull();
});

test('removing a signed bill copy deletes it from storage and clears the invoice', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-SIGNED-REMOVE',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
        'signed_copy_path' => 'signed-bills/existing.jpg',
    ]);
    Storage::disk('public')->put('signed-bills/existing.jpg', 'fake-contents');

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('removeSignedCopy');

    expect($invoice->fresh()->signed_copy_path)->toBeNull();
    Storage::disk('public')->assertMissing('signed-bills/existing.jpg');
});

test('an accountant can record a payment but gets forbidden deleting one', function () {
    Storage::fake('public');

    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::InvoicesView->value]);
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::PaymentsCreate->value]);

    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-PERM-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['company_id' => $company->id, 'service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $this->actingAs($accountant);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->set('paymentAmount', '1000')
        ->set('paymentDate', now()->toDateString())
        ->call('recordPayment')
        ->assertHasNoErrors();

    $payment = $invoice->fresh()->payments->first();
    expect($payment)->not->toBeNull();

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('confirmDeletePayment', $payment->id)
        ->call('deletePayment')
        ->assertForbidden();

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('delete')
        ->assertForbidden();
});

test('payment history paginates and its totals reflect every payment, not just the current page', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-PAGINATION-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_id' => $invoice->id,
        'bill_amount' => 10000,
    ]);

    // More than one screen page's worth (10 per page) of small payments.
    foreach (range(1, 12) as $i) {
        $invoice->payments()->create([
            'amount' => 100,
            'paid_on' => now()->subDays($i)->toDateString(),
        ]);
    }

    $this->actingAs($user);

    $component = Volt::test('invoices.invoice-detail', ['invoice' => $invoice]);

    // Only one page's worth of rows renders in the payment history list...
    expect($component->viewData('payments'))->toHaveCount(10);
    // ...but the balance owed accounts for all 12 payments (1200 total),
    // not just the 10 visible on this page.
    expect($component->viewData('totalPaidViaPayments'))->toBe(1200.0);
    expect($component->viewData('balanceDue'))->toBe(8800.0);
});
