<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\RolePermission;
use App\Models\SajjatTransaction;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Permission;
use App\Support\BusinessSnapshot;
use App\UserRole;
use Livewire\Volt\Volt;

function plEntry(array $attributes = []): JobEntry
{
    return JobEntry::factory()->create([
        'entry_date' => '2026-09-10',
        'bill_amount' => 1000,
        'cost_amount' => 600,
        ...$attributes,
    ]);
}

function plInvoice(Company $company, int $categoryId, array $attributes = []): Invoice
{
    return Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $categoryId,
        'invoice_number' => 'INV-'.fake()->unique()->numerify('####'),
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'status' => 'due',
        ...$attributes,
    ]);
}

function plMonth(string $month): array
{
    return BusinessSnapshot::profitAndLoss()['months']->first(fn (array $row) => $row['month']->format('Y-m') === $month);
}

test('a month is income less VAT, job costs, salaries, expenses and Sajjat spending', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $entry = plEntry(['bill_amount' => 10000, 'cost_amount' => 6000, 'shipment_tiffin_cost' => 100]);
    plEntry(['company_id' => $entry->company_id, 'service_category_id' => $entry->service_category_id, 'entry_date' => '2026-09-20', 'bill_amount' => 2000, 'cost_amount' => 1000]);
    $invoice = plInvoice($entry->company, $entry->service_category_id, ['vat_percent' => 5]);
    $entry->update(['invoice_id' => $invoice->id]);
    SalaryPayment::create(['employee_id' => Employee::factory()->create()->id, 'for_month' => '2026-09-01', 'amount' => 1500, 'paid_on' => '2026-10-02']);
    Expense::factory()->create(['expense_date' => '2026-09-05', 'amount' => 200]);
    SajjatTransaction::factory()->create(['transaction_date' => '2026-09-06', 'type' => SajjatTransaction::TYPE_EXPENSE, 'amount' => 300]);
    SajjatTransaction::factory()->create(['transaction_date' => '2026-09-06', 'type' => SajjatTransaction::TYPE_TOP_UP, 'amount' => 9999]);

    $september = plMonth('2026-09');

    expect($september['income'])->toBe(12000.0);
    expect($september['vat'])->toBe(500.0);
    expect($september['jobCosts'])->toBe(7100.0);
    expect($september['salaries'])->toBe(1500.0);
    expect($september['expenses'])->toBe(200.0);
    expect($september['sajjat'])->toBe(300.0);
    expect($september['jobProfit'])->toBe(4400.0);
    expect($september['net'])->toBe(2400.0);
    expect($september['margin'])->toBe(20.0);
});

test('costs above income show as a loss, in red words on the dashboard', function () {
    $this->travelTo('2026-10-15 12:00:00');
    plEntry(['bill_amount' => 1000, 'cost_amount' => 1500]);
    $this->actingAs(User::factory()->create());

    expect(plMonth('2026-09')['net'])->toBe(-500.0);

    Volt::test('dashboard.dashboard')
        ->assertSee('Profit &amp; Loss', false)
        ->assertSee('Last month — September 2026')
        ->assertSee('Loss 500.00');
});

test('months run newest first and the total adds them all up', function () {
    $this->travelTo('2026-11-15 12:00:00');
    plEntry(['entry_date' => '2026-09-10', 'bill_amount' => 1000, 'cost_amount' => 400]);
    plEntry(['entry_date' => '2026-11-02', 'bill_amount' => 500, 'cost_amount' => 100]);

    $report = BusinessSnapshot::profitAndLoss();

    expect($report['months']->map(fn (array $row) => $row['month']->format('Y-m'))->all())->toBe(['2026-11', '2026-10', '2026-09']);
    expect($report['months']->first()['isCurrent'])->toBeTrue();
    expect($report['months'][1]['net'])->toBe(0.0);
    expect($report['total']['income'])->toBe(1500.0);
    expect($report['total']['net'])->toBe(1000.0);
});

test('past paper bills count only with a recorded cost, and the rest are reported as left out', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $entry = plEntry(['bill_amount' => 1000, 'cost_amount' => 400]);
    plInvoice($entry->company, $entry->service_category_id, ['manual_amount' => 5000, 'manual_cost_amount' => 3000, 'period_start' => '2026-09-14', 'period_end' => '2026-09-14', 'vat_percent' => 10]);
    plInvoice($entry->company, $entry->service_category_id, ['manual_amount' => 18000000, 'period_start' => '2026-09-15', 'period_end' => '2026-09-15']);

    $report = BusinessSnapshot::profitAndLoss();
    $september = plMonth('2026-09');

    expect($september['income'])->toBe(6000.0);
    expect($september['jobCosts'])->toBe(3400.0);
    expect($september['vat'])->toBe(500.0);
    expect($september['net'])->toBe(2100.0);
    expect($report['uncountedBillCount'])->toBe(1);
    expect($report['uncountedBillAmount'])->toBe(18000000.0);
});

test('a 25 to 25 bill counts its VAT in the month it ends in', function () {
    $this->travelTo('2026-11-15 12:00:00');
    $entry = plEntry(['entry_date' => '2026-10-05', 'bill_amount' => 1000, 'cost_amount' => 0]);
    $invoice = plInvoice($entry->company, $entry->service_category_id, ['period_start' => '2026-09-25', 'period_end' => '2026-10-25', 'vat_percent' => 5]);
    $entry->update(['invoice_id' => $invoice->id]);

    expect(plMonth('2026-10')['vat'])->toBe(50.0);
});

test('with no job entries the panel says so instead of breaking', function () {
    $this->actingAs(User::factory()->create());

    expect(BusinessSnapshot::profitAndLoss()['months'])->toBeEmpty();
    Volt::test('dashboard.dashboard')->assertSee('Nothing recorded yet.')->assertDontSeeHtml('id="bills-not-submitted"');
});

test('profit and loss needs the profit permission, and the alert only needs the dashboard', function () {
    $this->travelTo('2026-10-15 12:00:00');
    plEntry();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::DashboardView->value]);
    $this->actingAs(User::factory()->staff()->create());

    Volt::test('dashboard.dashboard')
        ->assertDontSeeHtml('id="profit-and-loss"')
        ->assertSeeHtml('id="bills-not-submitted"');
});

test('someone without dashboard access sees neither', function () {
    $this->travelTo('2026-10-15 12:00:00');
    plEntry();
    $this->actingAs(User::factory()->staff()->create());

    Volt::test('dashboard.dashboard')
        ->assertDontSeeHtml('id="profit-and-loss"')
        ->assertDontSeeHtml('id="bills-not-submitted"');
});

test('a bill is late only after the 10th of the next month', function (string $today, int $expected) {
    $this->travelTo($today);
    plEntry(['entry_date' => '2026-09-30']);

    expect(BusinessSnapshot::billsNotSubmitted())->toHaveCount($expected);
})->with([
    'during the month' => ['2026-09-30 12:00:00', 0],
    '1st of the next month' => ['2026-10-01 12:00:00', 0],
    'on the 10th' => ['2026-10-10 23:00:00', 0],
    'on the 11th' => ['2026-10-11 00:30:00', 1],
    'much later' => ['2026-12-20 12:00:00', 1],
]);

test('an alert names the factory, service, month, amount and days late', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $company = Company::factory()->create(['name' => 'Late Factory Ltd']);
    plEntry(['company_id' => $company->id, 'bill_amount' => 700]);
    $this->actingAs(User::factory()->create());

    $bill = BusinessSnapshot::billsNotSubmitted()->sole();
    expect($bill['amount'])->toBe(700.0);
    expect($bill['daysLate'])->toBe(5);
    expect($bill['invoice'])->toBeNull();

    Volt::test('dashboard.dashboard')
        ->assertSee('Bills not submitted to the factory')
        ->assertSee('Late Factory Ltd')
        ->assertSee('September 2026')
        ->assertSee('Not generated')
        ->assertSee('5 days late')
        ->assertSeeHtml('period=2026-09');
});

test('a generated bill stays on the alert until its signed copy is uploaded', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $entry = plEntry();
    $invoice = plInvoice($entry->company, $entry->service_category_id);
    $entry->update(['invoice_id' => $invoice->id]);
    $this->actingAs(User::factory()->create());

    expect(BusinessSnapshot::billsNotSubmitted()->sole()['invoice']->id)->toBe($invoice->id);
    Volt::test('dashboard.dashboard')->assertSee('Generated, no signed copy');

    $invoice->update(['signed_copy_path' => 'signed-bills/copy.pdf']);

    expect(BusinessSnapshot::billsNotSubmitted())->toBeEmpty();
});

test('past paper bills and zero-amount work never trigger the alert', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $entry = plEntry(['bill_amount' => 0, 'cost_amount' => 50]);
    plInvoice($entry->company, $entry->service_category_id, ['manual_amount' => 5000]);

    expect(BusinessSnapshot::billsNotSubmitted())->toBeEmpty();
});

test('Progress work after the 25th waits for the next bill, not this month\'s deadline', function () {
    $this->travelTo('2026-11-15 12:00:00');
    $progress = Company::factory()->create(['code' => 'PAL']);
    $other = Company::factory()->create(['code' => 'OTH']);
    plEntry(['company_id' => $progress->id, 'entry_date' => '2026-10-20', 'bill_amount' => 100]);
    $afterCycle = plEntry(['company_id' => $progress->id, 'entry_date' => '2026-10-28', 'bill_amount' => 200]);
    plEntry(['company_id' => $other->id, 'entry_date' => '2026-10-28', 'bill_amount' => 400]);

    $late = BusinessSnapshot::billsNotSubmitted();

    expect($late->pluck('amount')->sort()->values()->all())->toBe([100.0, 400.0]);

    $this->travelTo('2026-12-11 12:00:00');
    expect(BusinessSnapshot::billsNotSubmitted()->firstWhere('amount', 200.0)['month']->format('Y-m'))->toBe('2026-11');
    expect($afterCycle->fresh()->invoice_id)->toBeNull();
});

test('before the deadline a reminder shows what is coming due', function () {
    $this->travelTo('2026-11-05 12:00:00');
    plEntry(['entry_date' => '2026-10-15', 'bill_amount' => 900]);
    $this->actingAs(User::factory()->create());

    expect(BusinessSnapshot::billsNotSubmitted())->toBeEmpty();
    expect(BusinessSnapshot::billsDueSoon()['amount'])->toBe(900.0);

    Volt::test('dashboard.dashboard')->assertSee('October bills are due by 10 November.');

    $this->travelTo('2026-11-11 12:00:00');
    expect(BusinessSnapshot::billsDueSoon())->toBeNull();
    expect(BusinessSnapshot::billsNotSubmitted())->toHaveCount(1);
});
