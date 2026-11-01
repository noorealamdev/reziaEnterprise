<?php

use App\Models\EggBuyer;
use App\Models\EggInvoice;
use App\Models\EggSale;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

function saleFor(EggBuyer $buyer, array $attributes = []): EggSale
{
    return EggSale::factory()->create([
        'egg_buyer_id' => $buyer->id,
        'sale_date' => '2026-09-10',
        'quantity' => 100,
        'sale_rate' => 12,
        'sale_amount' => 1200,
        ...$attributes,
    ]);
}

function billFor(EggBuyer $buyer, float $amount = 1000): EggInvoice
{
    $bill = EggInvoice::factory()->create(['egg_buyer_id' => $buyer->id]);
    saleFor($buyer, ['sale_amount' => $amount, 'egg_invoice_id' => $bill->id]);

    return $bill;
}

function staffWithEggPermissions(Permission ...$permissions): User
{
    foreach ($permissions as $permission) {
        RolePermission::create(['role' => UserRole::Staff->value, 'permission' => $permission->value]);
    }

    return User::factory()->staff()->create();
}

test('guests are redirected to login', function () {
    $this->get('/egg-sales')->assertRedirect('/login');
});

test('the page offers Sales, Bills and Buyers tabs', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/egg-sales')->assertOk()->assertSee('Sales')->assertSee('Bills')->assertSee('Buyers');

    Volt::test('egg-sales.sales-manager')->call('switchView', 'buyers')->assertSet('activeView', 'buyers')->assertSee('+ Add Buyer');
    Volt::test('egg-sales.sales-manager')->call('switchView', 'bills')->assertSet('activeView', 'bills')->assertSee('Ready to Bill');
    Volt::test('egg-sales.sales-manager')->call('switchView', 'nonsense')->assertSet('activeView', 'sales');
});

test('a sale is recorded with date, buyer, quantity, rate and remarks, and the amount is calculated', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create(['name' => 'Mr.Shafin']);

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->assertSet('sale_date', now()->toDateString())
        ->set('sale_date', '2026-10-05')
        ->set('buyer_name', 'Mr.Shafin')
        ->set('sale_quantity', '150')
        ->set('sale_rate', '12.5')
        ->assertSee('1,875.00')
        ->set('sale_remarks', 'Morning delivery')
        ->call('saveSale')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Sale recorded.', type: 'success');

    $sale = EggSale::sole();
    expect($sale->egg_buyer_id)->toBe($buyer->id);
    expect($sale->sale_date->toDateString())->toBe('2026-10-05');
    expect((float) $sale->sale_amount)->toBe(1875.0);
    expect($sale->remarks)->toBe('Morning delivery');
    expect($sale->egg_invoice_id)->toBeNull();
});

test('a sale needs a known active buyer, a quantity and a rate', function () {
    $this->actingAs(User::factory()->create());
    EggBuyer::factory()->create(['name' => 'Gone Buyer', 'is_active' => false]);

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->call('saveSale')
        ->assertHasErrors(['buyer_name', 'sale_quantity', 'sale_rate']);

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->set('buyer_name', 'Nobody')
        ->set('sale_quantity', '1')
        ->set('sale_rate', '1')
        ->call('saveSale')
        ->assertHasErrors(['buyer_name']);

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->set('buyer_name', 'Gone Buyer')
        ->set('sale_quantity', '1')
        ->set('sale_rate', '1')
        ->call('saveSale')
        ->assertHasErrors(['buyer_name']);

    expect(EggSale::count())->toBe(0);
});

test('changing the buyer clears an earlier buyer error', function () {
    $this->actingAs(User::factory()->create());
    EggBuyer::factory()->create(['name' => 'Real Buyer']);

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->set('buyer_name', 'Nobody')
        ->call('saveSale')
        ->assertHasErrors(['buyer_name'])
        ->set('buyer_name', 'Real Buyer')
        ->assertHasNoErrors(['buyer_name']);
});

test('choosing a buyer suggests their last rate but never overwrites a typed one', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create(['name' => 'Regular']);
    saleFor($buyer, ['sale_date' => '2026-09-01', 'sale_rate' => 11]);
    saleFor($buyer, ['sale_date' => '2026-09-20', 'sale_rate' => 12.5]);

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->set('buyer_name', 'Regular')
        ->assertSet('sale_rate', '12.5');

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->set('sale_rate', '20')
        ->set('buyer_name', 'Regular')
        ->assertSet('sale_rate', '20');
});

test('a new buyer can be added from the sale form and is selected straight away', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->call('openNewBuyerFields')
        ->set('new_buyer_name', 'Fresh Buyer')
        ->set('new_buyer_phone', '01700000000')
        ->call('addBuyer')
        ->assertHasNoErrors()
        ->assertSet('buyer_name', 'Fresh Buyer')
        ->assertSet('showNewBuyerFields', false);

    expect(EggBuyer::where('name', 'Fresh Buyer')->value('phone'))->toBe('01700000000');
});

test('editing a sale opens with its buyer filled in and can move it to another buyer', function () {
    $this->actingAs(User::factory()->create());
    $current = EggBuyer::factory()->create(['name' => 'Current Buyer']);
    $other = EggBuyer::factory()->create(['name' => 'Other Buyer']);
    $sale = saleFor($current);

    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $sale->id)
        ->assertSet('buyer_name', 'Current Buyer')
        ->assertSeeHtml('data-buyer="Current Buyer"')
        ->assertSeeHtml('data-buyer="Other Buyer"')
        ->set('buyer_name', 'Other Buyer')
        ->call('saveSale')
        ->assertHasNoErrors();

    expect($sale->fresh()->egg_buyer_id)->toBe($other->id);
    expect((float) $sale->fresh()->sale_amount)->toBe(1200.0);
});

test('an unbilled sale can be edited and deleted', function () {
    $this->actingAs(User::factory()->create());
    $sale = saleFor(EggBuyer::factory()->create());

    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $sale->id)
        ->set('sale_quantity', '200')
        ->call('saveSale')
        ->assertHasNoErrors();
    expect((float) $sale->fresh()->sale_amount)->toBe(2400.0);

    Volt::test('egg-sales.sale-list')->call('confirmDeleteSale', $sale->id)->call('deleteSale');
    expect(EggSale::count())->toBe(0);
});

test('a sale on a bill cannot be edited or deleted', function () {
    $this->actingAs(User::factory()->create());
    $bill = billFor(EggBuyer::factory()->create());
    $sale = $bill->sales()->sole();

    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $sale->id)
        ->assertSet('editingSaleId', null)
        ->assertDispatched('toast', type: 'error');

    Volt::test('egg-sales.sale-list')
        ->set('editingSaleId', $sale->id)
        ->set('buyer_name', $sale->buyer->name)
        ->set('sale_date', '2026-09-10')
        ->set('sale_quantity', '999')
        ->set('sale_rate', '999')
        ->call('saveSale');
    expect((float) $sale->fresh()->quantity)->toBe(100.0);

    Volt::test('egg-sales.sale-list')->call('confirmDeleteSale', $sale->id)->call('deleteSale');
    expect(EggSale::whereKey($sale->id)->exists())->toBeTrue();
});

test('sale filters narrow the list and totals, and no month is forced by default', function () {
    $this->actingAs(User::factory()->create());
    $first = EggBuyer::factory()->create();
    $second = EggBuyer::factory()->create();
    saleFor($first, ['sale_date' => '2026-09-05', 'quantity' => 10, 'sale_amount' => 100]);
    saleFor($first, ['sale_date' => '2026-10-05', 'quantity' => 20, 'sale_amount' => 200]);
    billFor($second, 500);

    $all = Volt::test('egg-sales.sale-list')->assertSet('yearFilter', '')->assertSet('monthFilter', '');
    expect($all->viewData('sales'))->toHaveCount(3);

    $october = Volt::test('egg-sales.sale-list')->set('yearFilter', '2026')->set('monthFilter', '10');
    expect($october->viewData('sales'))->toHaveCount(1);
    expect($october->viewData('filteredAmount'))->toBe(200.0);
    expect($october->viewData('filteredQuantity'))->toBe(20.0);

    expect(Volt::test('egg-sales.sale-list')->set('buyerFilter', (string) $first->id)->viewData('sales'))->toHaveCount(2);
    expect(Volt::test('egg-sales.sale-list')->set('billingFilter', 'unbilled')->viewData('sales'))->toHaveCount(2);
    expect(Volt::test('egg-sales.sale-list')->set('billingFilter', 'billed')->viewData('sales'))->toHaveCount(1);

    Volt::test('egg-sales.sale-list')
        ->set('buyerFilter', (string) $first->id)
        ->call('clearFilters')
        ->assertSet('buyerFilter', '');
});

test('the summary cards show this week, this month, unbilled and owed regardless of filters', function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    saleFor($buyer, ['sale_date' => '2026-10-14', 'quantity' => 40, 'sale_amount' => 400]);
    saleFor($buyer, ['sale_date' => '2026-10-02', 'quantity' => 30, 'sale_amount' => 300]);
    saleFor($buyer, ['sale_date' => '2026-09-02', 'quantity' => 20, 'sale_amount' => 200]);
    billFor($buyer, 1000)->payments()->create(['amount' => 250, 'paid_on' => '2026-10-01']);

    $component = Volt::test('egg-sales.sale-list')->set('yearFilter', '2020');

    expect($component->viewData('weekQuantity'))->toBe(40.0);
    expect($component->viewData('weekAmount'))->toBe(400.0);
    expect($component->viewData('monthQuantity'))->toBe(70.0);
    expect($component->viewData('monthAmount'))->toBe(700.0);
    expect($component->viewData('unbilledAmount'))->toBe(900.0);
    expect($component->viewData('owedOnBills'))->toBe(750.0);
});

test('a buyer can be added and edited, and names must be unique', function () {
    $this->actingAs(User::factory()->create());
    $existing = EggBuyer::factory()->create(['name' => 'Taken']);

    Volt::test('egg-sales.buyer-manager')
        ->call('startCreate')
        ->set('name', 'Taken')
        ->call('save')
        ->assertHasErrors(['name']);

    Volt::test('egg-sales.buyer-manager')
        ->call('startCreate')
        ->set('name', 'New Buyer')
        ->set('phone', '01800000000')
        ->set('address', 'Narayanganj')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Buyer added.', type: 'success');

    Volt::test('egg-sales.buyer-manager')
        ->call('startEdit', $existing->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($existing->fresh()->name)->toBe('Renamed');
    expect(EggBuyer::where('name', 'New Buyer')->value('address'))->toBe('Narayanganj');
});

test('only a buyer with no records can be deleted', function () {
    $this->actingAs(User::factory()->create());
    $unused = EggBuyer::factory()->create();
    $withSales = EggBuyer::factory()->create();
    saleFor($withSales);

    Volt::test('egg-sales.buyer-manager')->call('confirmDelete', $unused->id)->call('delete');
    expect(EggBuyer::whereKey($unused->id)->exists())->toBeFalse();

    Volt::test('egg-sales.buyer-manager')
        ->call('confirmDelete', $withSales->id)
        ->call('delete')
        ->assertDispatched('toast', type: 'error');
    expect(EggBuyer::whereKey($withSales->id)->exists())->toBeTrue();
});

test('an inactive buyer keeps their records but leaves the new-sale list', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create(['name' => 'Retiring']);
    $sale = saleFor($buyer);

    Volt::test('egg-sales.buyer-manager')->call('toggleActive', $buyer->id);
    expect($buyer->fresh()->is_active)->toBeFalse();

    $list = Volt::test('egg-sales.sale-list');
    expect($list->viewData('activeBuyers')->pluck('id'))->not->toContain($buyer->id);
    expect($list->viewData('sales')->pluck('id'))->toContain($sale->id);

    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $sale->id)
        ->set('sale_quantity', '50')
        ->call('saveSale')
        ->assertHasNoErrors();

    Volt::test('egg-sales.buyer-manager')->call('toggleActive', $buyer->id);
    expect($buyer->fresh()->is_active)->toBeTrue();
});

test('the buyers tab shows sold, billed, paid and still owed per buyer', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    saleFor($buyer, ['sale_amount' => 300]);
    billFor($buyer, 1000)->payments()->create(['amount' => 400, 'paid_on' => '2026-10-01']);

    $row = Volt::test('egg-sales.buyer-manager')->viewData('buyers')->firstWhere('id', $buyer->id);

    expect((float) $row->sold_total)->toBe(1300.0);
    expect((float) $row->billed_total)->toBe(1000.0);
    expect((float) $row->paid_total)->toBe(400.0);
});

test('ready to bill groups unbilled sales by buyer and month', function () {
    $this->actingAs(User::factory()->create());
    $first = EggBuyer::factory()->create(['name' => 'Alpha']);
    $second = EggBuyer::factory()->create(['name' => 'Beta']);
    saleFor($first, ['sale_date' => '2026-09-05', 'sale_amount' => 100]);
    saleFor($first, ['sale_date' => '2026-09-25', 'sale_amount' => 200]);
    saleFor($first, ['sale_date' => '2026-10-05', 'sale_amount' => 400]);
    saleFor($second, ['sale_date' => '2026-09-06', 'sale_amount' => 800]);
    billFor($second, 999);

    $rows = Volt::test('egg-sales.bill-list')->viewData('readyToBill');

    expect($rows)->toHaveCount(3);
    expect($rows->first(fn ($row) => $row->buyerName === 'Alpha' && $row->month === '2026-09')->total)->toBe(300.0);
    expect($rows->first(fn ($row) => $row->buyerName === 'Alpha' && $row->month === '2026-10')->total)->toBe(400.0);
    expect($rows->first(fn ($row) => $row->buyerName === 'Beta')->total)->toBe(800.0);
});

test('generating a bill collects only that buyer and month, and starts as Due', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    $other = EggBuyer::factory()->create();
    $september = saleFor($buyer, ['sale_date' => '2026-09-05', 'sale_amount' => 100]);
    $october = saleFor($buyer, ['sale_date' => '2026-10-05', 'sale_amount' => 400]);
    $otherSale = saleFor($other, ['sale_date' => '2026-09-05', 'sale_amount' => 800]);

    Volt::test('egg-sales.bill-list')
        ->call('confirmGenerate', $buyer->id, '2026-09')
        ->call('generateBill');

    $bill = EggInvoice::sole();
    expect($bill->invoice_number)->toBe("RE/EGG/B{$buyer->id}/092026");
    expect($bill->status)->toBe('due');
    expect($bill->total)->toBe(100.0);
    expect($bill->period_end->toDateString())->toBe('2026-09-30');
    expect($september->fresh()->egg_invoice_id)->toBe($bill->id);
    expect($october->fresh()->egg_invoice_id)->toBeNull();
    expect($otherSale->fresh()->egg_invoice_id)->toBeNull();
});

test('a sale added after the month was billed goes on a second bill with its own number', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    saleFor($buyer);
    Volt::test('egg-sales.bill-list')->call('confirmGenerate', $buyer->id, '2026-09')->call('generateBill');

    saleFor($buyer, ['sale_date' => '2026-09-28', 'sale_amount' => 50]);
    Volt::test('egg-sales.bill-list')->call('confirmGenerate', $buyer->id, '2026-09')->call('generateBill');

    expect(EggInvoice::pluck('invoice_number')->all())->toEqualCanonicalizing([
        "RE/EGG/B{$buyer->id}/092026",
        "RE/EGG/B{$buyer->id}/092026-2",
    ]);
});

test('generating a bill with nothing left to bill creates nothing', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();

    Volt::test('egg-sales.bill-list')
        ->call('confirmGenerate', $buyer->id, '2026-09')
        ->call('generateBill')
        ->assertDispatched('toast', type: 'error');

    expect(EggInvoice::count())->toBe(0);
});

test('the popup opens on the clicked month and can switch to another month of that buyer', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    $other = EggBuyer::factory()->create();
    saleFor($buyer, ['sale_date' => '2026-09-05', 'sale_amount' => 100]);
    $october = saleFor($buyer, ['sale_date' => '2026-10-05', 'sale_amount' => 400]);
    saleFor($other, ['sale_date' => '2026-08-05', 'sale_amount' => 800]);

    $popup = Volt::test('egg-sales.bill-list')->call('confirmGenerate', $buyer->id, '2026-09');

    expect($popup->viewData('billableMonths')->pluck('month')->all())->toBe(['2026-09', '2026-10']);
    expect($popup->viewData('billPreview')->total)->toBe(100.0);
    expect($popup->viewData('billPreview')->leftOut)->toBe(1);

    $popup->set('confirmingMonth', '2026-10')
        ->assertSet('billFrom', '2026-10-01')
        ->assertSet('billTo', '2026-10-31')
        ->call('generateBill');

    $bill = EggInvoice::sole();
    expect($bill->invoice_number)->toBe("RE/EGG/B{$buyer->id}/102026");
    expect($bill->periodLabel)->toBe('October 2026');
    expect($october->fresh()->egg_invoice_id)->toBe($bill->id);
});

test('custom dates bill only the sales inside them, including both end dates', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    $before = saleFor($buyer, ['sale_date' => '2026-09-09', 'sale_amount' => 1]);
    $firstDay = saleFor($buyer, ['sale_date' => '2026-09-10', 'sale_amount' => 10]);
    $lastDay = saleFor($buyer, ['sale_date' => '2026-09-20', 'sale_amount' => 100]);
    $after = saleFor($buyer, ['sale_date' => '2026-09-21', 'sale_amount' => 1000]);
    $otherBuyer = saleFor(EggBuyer::factory()->create(), ['sale_date' => '2026-09-15', 'sale_amount' => 5000]);

    $popup = Volt::test('egg-sales.bill-list')
        ->call('confirmGenerate', $buyer->id, '2026-09')
        ->set('billPeriodType', 'custom')
        ->set('billFrom', '2026-09-10')
        ->set('billTo', '2026-09-20');

    expect($popup->viewData('billPreview')->count)->toBe(2);
    expect($popup->viewData('billPreview')->total)->toBe(110.0);
    expect($popup->viewData('billPreview')->leftOut)->toBe(2);

    $popup->call('generateBill')->assertRedirect();

    $bill = EggInvoice::sole();
    expect($bill->total)->toBe(110.0);
    expect($bill->period_start->toDateString())->toBe('2026-09-10');
    expect($bill->period_end->toDateString())->toBe('2026-09-20');
    expect($firstDay->fresh()->egg_invoice_id)->toBe($bill->id);
    expect($lastDay->fresh()->egg_invoice_id)->toBe($bill->id);
    expect($before->fresh()->egg_invoice_id)->toBeNull();
    expect($after->fresh()->egg_invoice_id)->toBeNull();
    expect($otherBuyer->fresh()->egg_invoice_id)->toBeNull();

    expect(Volt::test('egg-sales.bill-list')->viewData('readyToBill')->firstWhere('buyerId', $buyer->id)->total)->toBe(1001.0);
});

test('custom dates can cross months and a later bill for the same start month gets its own number', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    saleFor($buyer, ['sale_date' => '2026-09-05', 'sale_amount' => 50]);
    saleFor($buyer, ['sale_date' => '2026-09-25', 'sale_amount' => 100]);
    saleFor($buyer, ['sale_date' => '2026-10-05', 'sale_amount' => 400]);

    Volt::test('egg-sales.bill-list')
        ->call('confirmGenerate', $buyer->id, '2026-09')
        ->set('billPeriodType', 'custom')
        ->set('billFrom', '2026-09-20')
        ->set('billTo', '2026-10-10')
        ->call('generateBill');
    Volt::test('egg-sales.bill-list')->call('confirmGenerate', $buyer->id, '2026-09')->call('generateBill');

    $crossing = EggInvoice::whereDate('period_end', '2026-10-10')->sole();
    expect($crossing->invoice_number)->toBe("RE/EGG/B{$buyer->id}/092026");
    expect($crossing->total)->toBe(500.0);
    expect(EggInvoice::where('invoice_number', "RE/EGG/B{$buyer->id}/092026-2")->sole()->total)->toBe(50.0);
    expect(EggSale::whereNull('egg_invoice_id')->count())->toBe(0);
});

test('invalid custom dates or dates with no sales bill nothing', function (string $from, string $to, bool $hasPreview) {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    saleFor($buyer, ['sale_date' => '2026-09-10']);

    $popup = Volt::test('egg-sales.bill-list')
        ->call('confirmGenerate', $buyer->id, '2026-09')
        ->set('billPeriodType', 'custom')
        ->set('billFrom', $from)
        ->set('billTo', $to);

    expect((bool) $popup->viewData('billPreview'))->toBe($hasPreview);
    $popup->call('generateBill')->assertDispatched('toast', type: 'error');

    expect(EggInvoice::count())->toBe(0);
})->with([
    'To before From' => ['2026-09-20', '2026-09-10', false],
    'empty From' => ['', '2026-09-10', false],
    'empty To' => ['2026-09-10', '', false],
    'impossible date' => ['2026-02-31', '2026-03-05', false],
    'no sales in range' => ['2026-09-11', '2026-09-12', true],
]);

test('a custom bill shows its exact dates while a whole month keeps the month wording', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create();
    $custom = billFor($buyer);
    $custom->update(['period_start' => '2026-09-10', 'period_end' => '2026-09-20']);
    $oneDay = billFor($buyer);
    $oneDay->update(['period_start' => '2026-09-25', 'period_end' => '2026-09-25']);
    $month = billFor($buyer);
    $month->update(['period_start' => '2026-08-01', 'period_end' => '2026-08-31']);

    Volt::test('egg-sales.bill-detail', ['bill' => $custom->fresh()])
        ->assertSee('Sub: Bill For Egg Supply Period Of 10-Sep-2026 to 20-Sep-2026.');
    Volt::test('egg-sales.bill-detail', ['bill' => $oneDay->fresh()])
        ->assertSee('Sub: Bill For Egg Supply Period Of 25-Sep-2026.');
    Volt::test('egg-sales.bill-detail', ['bill' => $month->fresh()])
        ->assertSee('Sub: Bill For Egg Supply Month Of August 2026.');
    Volt::test('egg-sales.bill-list')
        ->assertSee('10-Sep-2026 to 20-Sep-2026')
        ->assertSee('August 2026');
});

test('bills can be filtered by buyer, status and year', function () {
    $this->actingAs(User::factory()->create());
    $first = EggBuyer::factory()->create();
    $second = EggBuyer::factory()->create();
    billFor($first);
    billFor($second)->update(['status' => 'partial']);
    billFor($second)->update(['status' => 'paid', 'period_start' => '2025-01-01']);

    expect(Volt::test('egg-sales.bill-list')->viewData('bills'))->toHaveCount(3);
    expect(Volt::test('egg-sales.bill-list')->set('buyerFilter', (string) $first->id)->viewData('bills'))->toHaveCount(1);
    expect(Volt::test('egg-sales.bill-list')->set('statusFilter', 'unpaid')->viewData('bills'))->toHaveCount(2);
    expect(Volt::test('egg-sales.bill-list')->set('statusFilter', 'paid')->viewData('bills'))->toHaveCount(1);
    expect(Volt::test('egg-sales.bill-list')->set('yearFilter', '2025')->viewData('bills'))->toHaveCount(1);
});

test('payments move a bill from Due to Partially Paid to Paid', function () {
    $this->actingAs(User::factory()->create());
    $bill = billFor(EggBuyer::factory()->create());

    Volt::test('egg-sales.bill-detail', ['bill' => $bill])
        ->call('startRecordPayment')
        ->assertSet('paymentAmount', '1000')
        ->set('paymentAmount', '400')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Payment recorded.', type: 'success');
    expect($bill->fresh()->status)->toBe('partial');
    expect($bill->fresh()->balanceDue)->toBe(600.0);

    Volt::test('egg-sales.bill-detail', ['bill' => $bill->fresh()])
        ->call('startRecordPayment')
        ->assertSet('paymentAmount', '600')
        ->set('paymentType', 'check')
        ->set('checkNumber', '12345')
        ->set('bankName', 'City Bank')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $bill = $bill->fresh();
    expect($bill->status)->toBe('paid');
    expect($bill->paid_at)->not->toBeNull();
    expect($bill->payments()->latest('id')->first()->check_number)->toBe('12345');
});

test('the bill spells out the exact amount including paisa, before and after a payment', function () {
    $this->actingAs(User::factory()->create());
    $bill = billFor(EggBuyer::factory()->create(), 1961.25);

    Volt::test('egg-sales.bill-detail', ['bill' => $bill])
        ->assertSee('In Word: One Thousand Nine Hundred Sixty One Taka And Twenty Five Paisa Only.')
        ->call('startRecordPayment')
        ->set('paymentAmount', '1000')
        ->call('recordPayment')
        ->assertSee('In Word: Nine Hundred Sixty One Taka And Twenty Five Paisa Only.');
});

test('a payment cannot be more than the balance due or zero', function () {
    $this->actingAs(User::factory()->create());
    $bill = billFor(EggBuyer::factory()->create());

    Volt::test('egg-sales.bill-detail', ['bill' => $bill])
        ->call('startRecordPayment')
        ->set('paymentAmount', '1000.01')
        ->call('recordPayment')
        ->assertHasErrors(['paymentAmount']);

    Volt::test('egg-sales.bill-detail', ['bill' => $bill])
        ->call('startRecordPayment')
        ->set('paymentAmount', '0')
        ->call('recordPayment')
        ->assertHasErrors(['paymentAmount']);

    expect($bill->payments()->count())->toBe(0);
    expect($bill->fresh()->status)->toBe('due');
});

test('removing a payment recalculates the status', function () {
    $this->actingAs(User::factory()->create());
    $bill = billFor(EggBuyer::factory()->create());
    $payment = $bill->payments()->create(['amount' => 1000, 'paid_on' => '2026-10-01']);
    $bill->refreshStatus();
    expect($bill->fresh()->status)->toBe('paid');

    Volt::test('egg-sales.bill-detail', ['bill' => $bill->fresh()])
        ->call('confirmDeletePayment', $payment->id)
        ->call('deletePayment');

    expect($bill->fresh()->status)->toBe('due');
    expect($bill->fresh()->paid_at)->toBeNull();
});

test('deleting a bill returns its sales to ready to bill, but not once it has payments', function () {
    $this->actingAs(User::factory()->create());
    $bill = billFor(EggBuyer::factory()->create());
    $sale = $bill->sales()->sole();
    $payment = $bill->payments()->create(['amount' => 10, 'paid_on' => '2026-10-01']);

    Volt::test('egg-sales.bill-detail', ['bill' => $bill])->call('delete');
    expect(EggInvoice::whereKey($bill->id)->exists())->toBeTrue();
    expect($sale->fresh()->egg_invoice_id)->toBe($bill->id);

    $payment->delete();

    Volt::test('egg-sales.bill-detail', ['bill' => $bill->fresh()])->call('delete')->assertRedirect(route('egg-sales.index', ['view' => 'bills']));
    expect(EggInvoice::whereKey($bill->id)->exists())->toBeFalse();
    expect($sale->fresh()->egg_invoice_id)->toBeNull();
});

test('a signed copy can be uploaded and removed', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());
    $bill = billFor(EggBuyer::factory()->create());

    Volt::test('egg-sales.bill-detail', ['bill' => $bill])
        ->set('signedCopy', UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf'))
        ->call('uploadSignedCopy')
        ->assertHasNoErrors();

    $path = $bill->fresh()->signed_copy_path;
    Storage::disk('public')->assertExists($path);

    Volt::test('egg-sales.bill-detail', ['bill' => $bill->fresh()])->call('removeSignedCopy');
    Storage::disk('public')->assertMissing($path);
    expect($bill->fresh()->signed_copy_path)->toBeNull();
});

test('the printed bill is addressed to the buyer and lists each sale', function () {
    $this->actingAs(User::factory()->create());
    $buyer = EggBuyer::factory()->create(['name' => 'Mr.Yakub', 'address' => 'Adamjee', 'phone' => '01900000000']);
    $bill = EggInvoice::factory()->create(['egg_buyer_id' => $buyer->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
    saleFor($buyer, ['egg_invoice_id' => $bill->id, 'remarks' => 'Morning load']);

    $this->get(route('egg-sales.bills.show', $bill))
        ->assertOk()
        ->assertSee('Mr.Yakub')
        ->assertSee('Adamjee')
        ->assertSee('Bill For Egg Supply Month Of September 2026')
        ->assertSee('Morning load')
        ->assertSee('1,200.00')
        ->assertDontSee('The Managing Director');
});

test('a user with only egg_sales.view can look but not change anything', function () {
    $this->actingAs(staffWithEggPermissions(Permission::EggSalesView));
    $buyer = EggBuyer::factory()->create();
    $sale = saleFor($buyer);
    $bill = billFor($buyer);

    $this->get('/egg-sales')->assertOk();
    $this->get(route('egg-sales.bills.show', $bill))->assertOk()->assertDontSee('Record Payment')->assertDontSee('Delete Bill');

    Volt::test('egg-sales.sale-list')->assertDontSee('+ Record Sale')
        ->set('buyer_name', $buyer->name)->set('sale_date', '2026-09-01')->set('sale_quantity', '1')->set('sale_rate', '1')
        ->call('saveSale')->assertForbidden();
    Volt::test('egg-sales.sale-list')->call('confirmDeleteSale', $sale->id)->call('deleteSale')->assertForbidden();
    Volt::test('egg-sales.buyer-manager')->assertDontSee('+ Add Buyer')->set('name', 'X')->call('save')->assertForbidden();
    Volt::test('egg-sales.buyer-manager')->call('toggleActive', $buyer->id)->assertForbidden();
    Volt::test('egg-sales.bill-list')->assertDontSee('Generate Bill')->call('confirmGenerate', $buyer->id, '2026-09')->call('generateBill')->assertForbidden();
    Volt::test('egg-sales.bill-detail', ['bill' => $bill])->set('paymentAmount', '1')->set('paymentDate', '2026-10-01')->call('recordPayment')->assertForbidden();
    Volt::test('egg-sales.bill-detail', ['bill' => $bill])->call('delete')->assertForbidden();

    expect(EggInvoice::count())->toBe(1);
    expect(EggSale::count())->toBe(2);
});

test('egg_sales.create can add records but not edit or delete them', function () {
    $this->actingAs(staffWithEggPermissions(Permission::EggSalesView, Permission::EggSalesCreate));
    $buyer = EggBuyer::factory()->create(['name' => 'Staff Buyer']);
    $sale = saleFor($buyer);

    Volt::test('egg-sales.sale-list')
        ->call('startCreateSale')
        ->set('buyer_name', 'Staff Buyer')->set('sale_quantity', '1')->set('sale_rate', '1')
        ->call('saveSale')->assertHasNoErrors();
    Volt::test('egg-sales.buyer-manager')->call('startCreate')->set('name', 'Another')->call('save')->assertHasNoErrors();
    Volt::test('egg-sales.bill-list')->call('confirmGenerate', $buyer->id, '2026-09')->call('generateBill')->assertHasNoErrors();

    $bill = EggInvoice::sole();
    Volt::test('egg-sales.bill-detail', ['bill' => $bill])->call('startRecordPayment')->set('paymentAmount', '100')->call('recordPayment')->assertHasNoErrors();
    expect($bill->fresh()->status)->toBe('partial');

    Volt::test('egg-sales.sale-list')->call('confirmDeleteSale', $sale->id)->call('deleteSale')->assertForbidden();
    Volt::test('egg-sales.buyer-manager')->call('startEdit', $buyer->id)->set('name', 'Changed')->call('save')->assertForbidden();
    Volt::test('egg-sales.bill-detail', ['bill' => $bill->fresh()])->call('delete')->assertForbidden();
});

test('the sidebar links to Egg Sales only for users who can view it', function () {
    $this->actingAs(staffWithEggPermissions(Permission::EggSalesView));
    $this->get('/dashboard')->assertSee(route('egg-sales.index'), false);

    $this->actingAs(User::factory()->accountant()->create());
    $this->get('/dashboard')->assertDontSee(route('egg-sales.index'), false);
});

test('users without egg sales permission cannot open the pages', function () {
    $bill = billFor(EggBuyer::factory()->create());

    $this->actingAs(User::factory()->accountant()->create());
    $this->get('/egg-sales')->assertForbidden();
    $this->get(route('egg-sales.bills.show', $bill))->assertForbidden();

    auth()->logout();
    $this->get(route('egg-sales.bills.show', $bill))->assertRedirect('/login');
});
