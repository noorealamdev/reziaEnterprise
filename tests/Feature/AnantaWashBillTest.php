<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use App\Models\TiffinDepartment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

/**
 * @return array{company: Company, tiffin: ServiceCategory, worker: TiffinDepartment, staff: TiffinDepartment, swing: TiffinDepartment}
 */
function anantaWashSetup(): array
{
    $company = Company::factory()->create(['code' => 'AAL', 'name' => 'Ananta Apparels Ltd', 'tiffin_bill_rate' => 30]);
    $tiffin = makeServiceCategory('Tiffin');
    $worker = TiffinDepartment::create(['name' => 'Wash Worker']);
    $staff = TiffinDepartment::create(['name' => 'Wash Staff']);
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach([$worker->id, $staff->id, $swing->id]);
    makeTiffinRecipe($worker, ['Banana', 'Milk']);
    makeTiffinRecipe($staff, ['Banana', 'Bread', 'Egg']);
    makeTiffinRecipe($swing, ['Banana', 'Bread', 'Egg']);

    return compact('company', 'tiffin', 'worker', 'staff', 'swing');
}

function washEntry(array $setup, TiffinDepartment $department, string $date, string $item, float $quantity, float $rate): JobEntry
{
    return JobEntry::factory()->create([
        'company_id' => $setup['company']->id,
        'service_category_id' => $setup['tiffin']->id,
        'tiffin_department_id' => $department->id,
        'entry_date' => $date,
        'supply_type' => $item,
        'quantity' => $quantity,
        'bill_rate' => $rate,
        'bill_amount' => $quantity * $rate,
        'cost_rate' => 5,
        'cost_amount' => $quantity * 5,
    ]);
}

/**
 * Two real days from the factory copy: 1 Sep with Banana, 3 Sep with Milk.
 */
function washDays(array $setup): void
{
    washEntry($setup, $setup['worker'], '2026-09-01', 'Banana', 599, 6);
    washEntry($setup, $setup['worker'], '2026-09-01', 'Milk', 0, 22);
    washEntry($setup, $setup['staff'], '2026-09-01', 'Banana', 136, 0);
    washEntry($setup, $setup['staff'], '2026-09-01', 'Egg', 136, 30);
    washEntry($setup, $setup['worker'], '2026-09-03', 'Banana', 0, 6);
    washEntry($setup, $setup['worker'], '2026-09-03', 'Milk', 547, 22);
    washEntry($setup, $setup['staff'], '2026-09-03', 'Banana', 129, 0);
    washEntry($setup, $setup['staff'], '2026-09-03', 'Egg', 129, 30);
}

function generateWashBill(array $setup, string $number = 'RE/AAL/TS-W/Bill#24/092026')
{
    return Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $setup['company']->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->set('tiffin_department_id', $setup['worker']->id)
        ->set('period', '2026-09')
        ->set('invoice_number', $number);
}

test('the invoice form offers one Wash choice for Ananta, and Swing stays separate', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();

    $departments = Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $setup['company']->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->viewData('tiffinDepartments');

    expect($departments->pluck('name')->all())->toBe(['Swing', 'Wash (Worker + Staff)']);
    expect($departments->pluck('id')->all())->toBe([$setup['swing']->id, $setup['worker']->id]);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $setup['company']->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->assertSee('Wash Worker and Wash Staff go on one bill together.')
        ->assertDontSee('Swing and Wash Worker each get their own invoice');
});

test('other companies keep their departments as they are', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    $other = Company::factory()->create(['code' => 'OTH']);
    $other->tiffinDepartments()->attach([$setup['worker']->id, $setup['staff']->id]);

    $departments = Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $other->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->viewData('tiffinDepartments');

    expect($departments->pluck('name')->all())->toBe(['Wash Staff', 'Wash Worker']);
});

test('one bill collects Worker and Staff for the month, and leaves Swing out', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    washDays($setup);
    $swing = washEntry($setup, $setup['swing'], '2026-09-01', 'Egg', 100, 30);

    $form = generateWashBill($setup);
    expect($form->viewData('preview')['count'])->toBe(8);
    expect((float) $form->viewData('preview')['total'])->toBe(3594.0 + 4080.0 + 12034.0 + 3870.0);

    $form->call('generate')->assertHasNoErrors();

    $invoice = Invoice::sole();
    expect($invoice->jobEntries()->count())->toBe(8);
    expect($swing->fresh()->invoice_id)->toBeNull();
});

test('Swing still bills on its own', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    washDays($setup);
    $swing = washEntry($setup, $setup['swing'], '2026-09-01', 'Egg', 100, 30);

    generateWashBill($setup, 'RE/AAL/TS-S/1/092026')
        ->set('tiffin_department_id', $setup['swing']->id)
        ->set('period', '2026-09')
        ->call('generate')
        ->assertHasNoErrors();

    expect(Invoice::sole()->jobEntries()->pluck('id')->all())->toBe([$swing->id]);
});

test('the bill prints like the factory copy, with Worker and Staff side by side', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    washDays($setup);
    generateWashBill($setup)->call('generate')->assertHasNoErrors();

    $bill = Volt::test('invoices.invoice-detail', ['invoice' => Invoice::sole()]);

    $bill->assertSee('Sub: Bill For Tiffin Supply (Wash) Month Of Sep-2026.')
        ->assertSeeInOrder(['Worker', 'Staff', 'Total Price', 'Item', 'Qty', 'Rate', 'Price', 'Item', 'Qty', 'Rate', 'Price'])
        ->assertSeeInOrder(['1-Sep-26', 'Banana', '599', '6', '3594', 'Tiffin', '136', '30', '4080', '7674'])
        ->assertSeeInOrder(['3-Sep-26', 'Milk', '547', '22', '12034', 'Tiffin', '129', '30', '3870', '15904'])
        ->assertSee('Grand Total =')
        ->assertSee('23,578.0৳')
        ->assertSee('Twenty Three Thousand Five Hundred Seventy Eight Taka Only.')
        ->assertSeeInOrder(['Authorised', 'Thanking By'])
        ->assertDontSee('Client Signature');

    $wash = $bill->viewData('washBill');
    expect($wash['rows'])->toHaveCount(2);
    expect($wash['workerQuantity'])->toBe(1146.0);
    expect($wash['workerPrice'])->toBe(15628.0);
    expect($wash['staffQuantity'])->toBe(265.0);
    expect($wash['staffPrice'])->toBe(7950.0);
    expect($wash['total'])->toBe(23578.0);
});

test('staff quantity on the bill is headcount, never the egg count with its buffer', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    washEntry($setup, $setup['worker'], '2026-09-04', 'Banana', 54, 6);
    JobEntry::factory()->create([
        'company_id' => $setup['company']->id,
        'service_category_id' => $setup['tiffin']->id,
        'tiffin_department_id' => $setup['staff']->id,
        'entry_date' => '2026-09-04',
        'supply_type' => 'Egg',
        'quantity' => 38,
        'bill_rate' => 30,
        'bill_amount' => 990,
    ]);
    generateWashBill($setup)->call('generate')->assertHasNoErrors();

    $row = Volt::test('invoices.invoice-detail', ['invoice' => Invoice::sole()])->viewData('washBill')['rows']->sole();

    expect($row->staffQuantity)->toBe(33.0);
    expect($row->staffPrice)->toBe(990.0);
});

test('a day with both Banana and Milk shows both, and counts the staff once', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    washEntry($setup, $setup['worker'], '2026-09-01', 'Banana', 100, 6);
    washEntry($setup, $setup['worker'], '2026-09-01', 'Milk', 50, 22);
    washEntry($setup, $setup['staff'], '2026-09-01', 'Egg', 10, 30);
    generateWashBill($setup)->call('generate')->assertHasNoErrors();

    $wash = Volt::test('invoices.invoice-detail', ['invoice' => Invoice::sole()])->viewData('washBill');

    expect($wash['rows'])->toHaveCount(2);
    expect($wash['staffPrice'])->toBe(300.0);
    expect($wash['total'])->toBe(600.0 + 1100.0 + 300.0);
});

test('other invoices keep the normal layout', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    washEntry($setup, $setup['swing'], '2026-09-01', 'Egg', 100, 30);
    generateWashBill($setup, 'RE/AAL/TS-S/1/092026')
        ->set('tiffin_department_id', $setup['swing']->id)
        ->set('period', '2026-09')
        ->call('generate');

    Volt::test('invoices.invoice-detail', ['invoice' => Invoice::sole()])
        ->assertSet('invoice.invoice_number', 'RE/AAL/TS-S/1/092026')
        ->assertSee('Client Signature')
        ->assertDontSee('Grand Total =')
        ->assertDontSeeHtml('id="wash-bill"')
        ->assertSee('Sub: Bill For Tiffin Month Of September 2026.');
});

test('a new day prices Wash Workers at Banana 6 and Milk 22 and can be changed', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    $workerId = $setup['worker']->id;

    $form = Volt::test('job-entries.job-entry-form')
        ->set('company_id', $setup['company']->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->assertSet("batchBillRates.{$workerId}.Banana", '6')
        ->assertSet("batchBillRates.{$workerId}.Milk", '22')
        ->set('entry_date', '2026-11-01')
        ->set("batchQuantities.{$workerId}.Banana", '599')
        ->set("batchCostRates.{$workerId}.Banana", '5.2')
        ->set("batchQuantities.{$workerId}.Milk", '10')
        ->set("batchCostRates.{$workerId}.Milk", '25')
        ->set("batchBillRates.{$workerId}.Milk", '23');

    $form->call('saveTiffinItemBatch')->assertHasNoErrors();

    $banana = JobEntry::where('supply_type', 'Banana')->sole();
    expect((float) $banana->bill_rate)->toBe(6.0);
    expect((float) $banana->bill_amount)->toBe(3594.0);
    expect((float) $banana->profit_amount)->toBe(479.2);

    $milk = JobEntry::where('supply_type', 'Milk')->sole();
    expect((float) $milk->bill_rate)->toBe(23.0);
    expect((float) $milk->bill_amount)->toBe(230.0);
});

test('a Wash Worker department at another company is not billed per item', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    $other = Company::factory()->create(['code' => 'OTH']);
    $other->serviceCategories()->attach($setup['tiffin']);
    $other->tiffinDepartments()->attach($setup['worker']);
    $workerId = $setup['worker']->id;

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $other->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->assertSet('batchBillRates', [])
        ->set('entry_date', '2026-11-01')
        ->set("batchQuantities.{$workerId}.Banana", '100')
        ->set("batchCostRates.{$workerId}.Banana", '5')
        ->set("batchQuantities.{$workerId}.Milk", '10')
        ->set("batchCostRates.{$workerId}.Milk", '25')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    expect((float) JobEntry::where('supply_type', 'Banana')->sole()->bill_amount)->toBe(0.0);
    expect((float) JobEntry::where('supply_type', 'Milk')->sole()->bill_amount)->toBe(0.0);

    $entry = JobEntry::where('supply_type', 'Banana')->sole();
    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $other, 'department' => $setup['worker'], 'date' => '2026-11-01'])
        ->set('quantities.Banana', '200')
        ->call('save')
        ->assertHasNoErrors();
    expect((float) $entry->fresh()->bill_amount)->toBe(0.0);
});

test('other companies invoices keep the normal layout, even with a Wash Worker department', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    $other = Company::factory()->create(['code' => 'OTH']);
    $other->tiffinDepartments()->attach($setup['worker']);
    JobEntry::factory()->create([
        'company_id' => $other->id,
        'service_category_id' => $setup['tiffin']->id,
        'tiffin_department_id' => $setup['worker']->id,
        'entry_date' => '2026-09-01',
        'supply_type' => 'Egg',
        'quantity' => 10,
        'bill_rate' => 30,
        'bill_amount' => 300,
    ]);

    Volt::test('invoices.invoice-generate-form')
        ->set('company_id', $other->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->set('tiffin_department_id', $setup['worker']->id)
        ->set('period', '2026-09')
        ->set('invoice_number', 'RE/OTH/1')
        ->call('generate')
        ->assertHasNoErrors();

    Volt::test('invoices.invoice-detail', ['invoice' => Invoice::sole()])
        ->assertDontSeeHtml('id="wash-bill"')
        ->assertSee('Client Signature');
});

test('other departments still bill only on Egg', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    $swingId = $setup['swing']->id;

    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $setup['company']->id)
        ->set('service_category_id', $setup['tiffin']->id)
        ->assertSet("batchBillRates.{$swingId}", ['Egg' => '30.00'])
        ->set('entry_date', '2026-11-01')
        ->set("batchQuantities.{$swingId}.Banana", '100')
        ->set("batchCostRates.{$swingId}.Banana", '5')
        ->set("batchQuantities.{$swingId}.Bread", '100')
        ->set("batchCostRates.{$swingId}.Bread", '8')
        ->set("batchQuantities.{$swingId}.Egg", '100')
        ->set("batchCostRates.{$swingId}.Egg", '11')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    expect((float) JobEntry::where('supply_type', 'Banana')->sole()->bill_amount)->toBe(0.0);
    expect((float) JobEntry::where('supply_type', 'Bread')->sole()->bill_amount)->toBe(0.0);
    expect((float) JobEntry::where('supply_type', 'Egg')->sole()->bill_amount)->toBe(3000.0);
});

test('editing a Wash Worker day keeps its price and can move it from Banana to Milk', function () {
    $this->actingAs(User::factory()->create());
    $setup = anantaWashSetup();
    $banana = washEntry($setup, $setup['worker'], '2026-09-03', 'Banana', 547, 6);
    $milk = washEntry($setup, $setup['worker'], '2026-09-03', 'Milk', 0, 22);

    Volt::test('job-entries.tiffin-batch-edit-form', ['company' => $setup['company'], 'department' => $setup['worker'], 'date' => '2026-09-03'])
        ->assertSet('billRates.Milk', '22.00')
        ->set('quantities.Banana', '0')
        ->set('quantities.Milk', '547')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $banana->fresh()->bill_amount)->toBe(0.0);
    expect((float) $milk->fresh()->bill_amount)->toBe(12034.0);
});

test('the update prices existing Worker entries, and leaves everything else alone', function () {
    $setup = anantaWashSetup();
    $banana = washEntry($setup, $setup['worker'], '2026-09-01', 'Banana', 599, 0);
    $milk = washEntry($setup, $setup['worker'], '2026-09-03', 'Milk', 547, 0);
    $staffBanana = washEntry($setup, $setup['staff'], '2026-09-01', 'Banana', 136, 0);
    $swingBanana = washEntry($setup, $setup['swing'], '2026-09-01', 'Banana', 100, 0);
    $billed = washEntry($setup, $setup['worker'], '2026-08-01', 'Banana', 10, 0);
    $billed->update(['invoice_id' => Invoice::create([
        'company_id' => $setup['company']->id,
        'service_category_id' => $setup['tiffin']->id,
        'invoice_number' => 'OLD',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ])->id]);

    (require database_path('migrations/2026_11_01_164820_bill_ananta_wash_workers.php'))->up();

    expect((float) $banana->fresh()->bill_rate)->toBe(6.0);
    expect((float) $banana->fresh()->bill_amount)->toBe(3594.0);
    expect((float) $banana->fresh()->profit_amount)->toBe(3594.0 - 2995.0);
    expect((float) $milk->fresh()->bill_rate)->toBe(22.0);
    expect((float) $milk->fresh()->bill_amount)->toBe(12034.0);
    expect((float) $staffBanana->fresh()->bill_amount)->toBe(0.0);
    expect((float) $swingBanana->fresh()->bill_amount)->toBe(0.0);
    expect((float) $billed->fresh()->bill_amount)->toBe(0.0);
});

test('the update does nothing on a database without Ananta', function () {
    (require database_path('migrations/2026_11_01_164820_bill_ananta_wash_workers.php'))->up();

    expect(DB::table('job_entries')->count())->toBe(0);
});
