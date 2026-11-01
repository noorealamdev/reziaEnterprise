<?php

use App\Models\EggBuyer;
use App\Models\EggCashHandover;
use App\Models\EggInvoice;
use App\Models\EggSale;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

function walletBuyer(float $sold = 1000): EggBuyer
{
    $buyer = EggBuyer::factory()->cashWallet()->create(['name' => 'Mr.Akter']);

    if ($sold > 0) {
        walletSale($buyer, $sold);
    }

    return $buyer;
}

function walletSale(EggBuyer $buyer, float $amount, string $date = '2026-09-10'): EggSale
{
    return EggSale::factory()->create([
        'egg_buyer_id' => $buyer->id,
        'sale_date' => $date,
        'quantity' => 100,
        'sale_rate' => $amount / 100,
        'sale_amount' => $amount,
    ]);
}

function handOver(EggBuyer $buyer, float $amount, string $date = '2026-09-15'): EggCashHandover
{
    return EggCashHandover::factory()->create(['egg_buyer_id' => $buyer->id, 'amount' => $amount, 'handed_on' => $date]);
}

function staffWithWalletPermissions(Permission ...$permissions): User
{
    foreach ($permissions as $permission) {
        RolePermission::create(['role' => UserRole::Staff->value, 'permission' => $permission->value]);
    }

    return User::factory()->staff()->create();
}

test('the wallet tab only appears when a buyer uses a cash wallet', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')->assertDontSee('Wallet');

    walletBuyer();

    Volt::test('egg-sales.sales-manager')
        ->assertSee('Mr.Akter Wallet')
        ->call('switchView', 'wallet')
        ->assertSet('activeView', 'wallet');
});

test('the wallet balance is sales minus cash handed to the office', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(1000);
    walletSale($buyer, 500);
    handOver($buyer, 300);

    $wallet = Volt::test('egg-sales.cash-wallet');

    expect($wallet->viewData('totalSold'))->toBe(1500.0);
    expect($wallet->viewData('totalHanded'))->toBe(300.0);
    expect($wallet->viewData('balance'))->toBe(1200.0);
});

test('a handover is recorded with date, amount and note, and lowers the balance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $buyer = walletBuyer(1000);

    Volt::test('egg-sales.cash-wallet')
        ->call('startCreate')
        ->set('handed_on', '2026-10-05')
        ->set('amount', '400')
        ->set('note', 'Weekly cash')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Handover recorded.', type: 'success');

    $handover = EggCashHandover::sole();
    expect($handover->egg_buyer_id)->toBe($buyer->id);
    expect($handover->handed_on->toDateString())->toBe('2026-10-05');
    expect((float) $handover->amount)->toBe(400.0);
    expect($handover->note)->toBe('Weekly cash');
    expect($handover->created_by)->toBe($user->id);
    expect($buyer->walletBalance())->toBe(600.0);
});

test('a handover can use the exact balance but never more', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(1000);

    Volt::test('egg-sales.cash-wallet')
        ->call('startCreate')
        ->set('amount', '1000.01')
        ->call('save')
        ->assertHasErrors(['amount']);
    expect(EggCashHandover::count())->toBe(0);

    Volt::test('egg-sales.cash-wallet')
        ->call('startCreate')
        ->set('amount', '1000')
        ->call('save')
        ->assertHasNoErrors();
    expect($buyer->walletBalance())->toBe(0.0);

    Volt::test('egg-sales.cash-wallet')
        ->call('startCreate')
        ->set('amount', '0.01')
        ->call('save')
        ->assertHasErrors(['amount']);
    expect(EggCashHandover::count())->toBe(1);
});

test('a handover amount must be positive with at most two decimals', function (string $amount) {
    $this->actingAs(User::factory()->create());
    walletBuyer(1000);

    Volt::test('egg-sales.cash-wallet')
        ->call('startCreate')
        ->set('amount', $amount)
        ->call('save')
        ->assertHasErrors(['amount']);
})->with(['zero' => '0', 'negative' => '-5', 'three decimals' => '1.001', 'empty' => '']);

test('editing a handover can use its own amount plus the balance, but no more', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(1000);
    $handover = handOver($buyer, 600);

    Volt::test('egg-sales.cash-wallet')
        ->call('startEdit', $handover->id)
        ->assertSet('handed_on', '2026-09-15')
        ->set('amount', '1000.01')
        ->call('save')
        ->assertHasErrors(['amount']);
    expect((float) $handover->fresh()->amount)->toBe(600.0);

    Volt::test('egg-sales.cash-wallet')
        ->call('startEdit', $handover->id)
        ->set('amount', '1000')
        ->call('save')
        ->assertHasNoErrors();
    expect($buyer->walletBalance())->toBe(0.0);
});

test('deleting a handover puts the cash back in the balance', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(1000);
    $handover = handOver($buyer, 600);

    Volt::test('egg-sales.cash-wallet')->call('confirmDelete', $handover->id)->call('delete');

    expect(EggCashHandover::count())->toBe(0);
    expect($buyer->walletBalance())->toBe(1000.0);
});

test('the statement runs newest first with the balance after each entry', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(0);
    walletSale($buyer, 1000, '2026-09-01');
    handOver($buyer, 400, '2026-09-05');
    walletSale($buyer, 200, '2026-09-05');

    $entries = Volt::test('egg-sales.cash-wallet')->viewData('entries');

    expect($entries->pluck('balance')->all())->toBe([800.0, 1200.0, 1000.0]);
    expect($entries->pluck('type')->all())->toBe(['handover', 'sale', 'sale']);
});

test('filters narrow the entries and period totals but never the wallet balance', function () {
    $this->travelTo('2026-10-20 12:00:00');
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(0);
    walletSale($buyer, 1000, '2026-09-10');
    walletSale($buyer, 200, '2026-10-20');
    handOver($buyer, 300, '2026-10-18');

    $today = Volt::test('egg-sales.cash-wallet')->set('rangeFilter', '1');
    expect($today->viewData('entries'))->toHaveCount(1);
    expect($today->viewData('soldInView'))->toBe(200.0);
    expect($today->viewData('balance'))->toBe(900.0);

    expect(Volt::test('egg-sales.cash-wallet')->set('rangeFilter', '7')->viewData('entries'))->toHaveCount(2);

    $september = Volt::test('egg-sales.cash-wallet')->set('yearFilter', '2026')->set('monthFilter', '9');
    expect($september->viewData('entries'))->toHaveCount(1);
    expect($september->viewData('soldInView'))->toBe(1000.0);

    $dates = Volt::test('egg-sales.cash-wallet')->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-18');
    expect($dates->viewData('entries'))->toHaveCount(1);
    expect($dates->viewData('handedInView'))->toBe(300.0);

    expect(Volt::test('egg-sales.cash-wallet')->set('typeFilter', 'handover')->viewData('entries')->pluck('type')->all())->toBe(['handover']);
    expect(Volt::test('egg-sales.cash-wallet')->set('typeFilter', 'sale')->viewData('entries'))->toHaveCount(2);
});

test('the grand total covers every page, and follows the filters', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(0);
    foreach (range(1, 30) as $day) {
        walletSale($buyer, 100, '2026-09-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
    }
    walletSale($buyer, 50, '2026-10-05');
    handOver($buyer, 1000, '2026-10-06');

    $wallet = Volt::test('egg-sales.cash-wallet')
        ->assertSeeInOrder(['Grand Total', '3,100 eggs', '3,050.00', '1,000.00', '2,050.00']);
    expect($wallet->viewData('entries'))->toHaveCount(25);

    $wallet->call('setPage', 2)
        ->assertSeeInOrder(['Grand Total', '3,100 eggs', '3,050.00', '1,000.00', '2,050.00']);

    Volt::test('egg-sales.cash-wallet')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '10')
        ->assertDontSee('Grand Total')
        ->assertSeeInOrder(['Total for these filters', '100 eggs', '50.00', '1,000.00']);
});

test('a long statement is paged', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(0);
    foreach (range(1, 30) as $day) {
        walletSale($buyer, 100, '2026-09-'.str_pad((string) min($day, 30), 2, '0', STR_PAD_LEFT));
    }

    $wallet = Volt::test('egg-sales.cash-wallet');
    expect($wallet->viewData('entries'))->toHaveCount(25);

    $wallet->call('setPage', 2);
    expect($wallet->viewData('entries'))->toHaveCount(5);
});

test('a wallet sale cannot shrink past cash already handed to the office', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(0);
    $sale = walletSale($buyer, 1000);
    handOver($buyer, 800);

    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $sale->id)
        ->set('sale_quantity', '50')
        ->set('sale_rate', '10')
        ->call('saveSale')
        ->assertHasErrors(['sale_quantity']);
    expect((float) $sale->fresh()->sale_amount)->toBe(1000.0);

    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $sale->id)
        ->set('sale_quantity', '80')
        ->set('sale_rate', '10')
        ->call('saveSale')
        ->assertHasNoErrors();
    expect($buyer->walletBalance())->toBe(0.0);
});

test('a wallet sale funding a handover cannot be deleted', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(0);
    $funding = walletSale($buyer, 1000);
    $spare = walletSale($buyer, 200);
    handOver($buyer, 1000);

    Volt::test('egg-sales.sale-list')
        ->call('confirmDeleteSale', $funding->id)
        ->call('deleteSale')
        ->assertDispatched('toast', type: 'error');
    expect($funding->fresh())->not->toBeNull();

    Volt::test('egg-sales.sale-list')
        ->call('confirmDeleteSale', $spare->id)
        ->call('deleteSale')
        ->assertDispatched('toast', message: 'Sale deleted.', type: 'success');
    expect($spare->fresh())->toBeNull();
});

test('a wallet sale cannot move to another buyer past cash already handed over', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(0);
    $sale = walletSale($buyer, 1000);
    handOver($buyer, 500);
    $other = EggBuyer::factory()->create(['name' => 'Mr.Shafin']);

    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $sale->id)
        ->set('buyer_name', 'Mr.Shafin')
        ->call('saveSale')
        ->assertHasErrors(['buyer_name']);
    expect($sale->fresh()->egg_buyer_id)->toBe($buyer->id);

    $free = walletSale($buyer, 500);
    Volt::test('egg-sales.sale-list')
        ->call('startEditSale', $free->id)
        ->set('buyer_name', 'Mr.Shafin')
        ->call('saveSale')
        ->assertHasNoErrors();
    expect($free->fresh()->egg_buyer_id)->toBe($other->id);
});

test('other buyers sales have no wallet limit', function () {
    $this->actingAs(User::factory()->create());
    walletBuyer();
    $sale = walletSale(EggBuyer::factory()->create(), 1000);

    Volt::test('egg-sales.sale-list')
        ->call('confirmDeleteSale', $sale->id)
        ->call('deleteSale')
        ->assertDispatched('toast', message: 'Sale deleted.', type: 'success');
});

test('the wallet buyer is never offered a bill and cannot be billed', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer();
    $other = EggBuyer::factory()->create();
    walletSale($other, 300);

    $rows = Volt::test('egg-sales.bill-list')->viewData('readyToBill');
    expect($rows->pluck('buyerId')->all())->toBe([$other->id]);

    Volt::test('egg-sales.bill-list')
        ->call('confirmGenerate', $buyer->id, '2026-09')
        ->call('generateBill')
        ->assertDispatched('toast', type: 'error');
    expect(EggInvoice::count())->toBe(0);
    expect(EggSale::where('egg_buyer_id', $buyer->id)->whereNotNull('egg_invoice_id')->count())->toBe(0);

    expect(Volt::test('egg-sales.bill-list')->viewData('buyerOptions')->pluck('id')->all())->toBe([$other->id]);
});

test('wallet sales stay out of the not billed figures but count as sold', function () {
    $this->travelTo('2026-09-15 12:00:00');
    $this->actingAs(User::factory()->create());
    walletBuyer(1000);
    $other = EggBuyer::factory()->create();
    walletSale($other, 300);

    $list = Volt::test('egg-sales.sale-list');
    expect($list->viewData('unbilledAmount'))->toBe(300.0);
    expect($list->viewData('unbilledCount'))->toBe(1);
    expect($list->viewData('monthAmount'))->toBe(1300.0);
    $list->assertSee('Cash wallet');

    expect(Volt::test('egg-sales.sale-list')->set('billingFilter', 'unbilled')->viewData('filteredAmount'))->toBe(300.0);
    expect(Volt::test('egg-sales.sale-list')->set('billingFilter', 'wallet')->viewData('filteredAmount'))->toBe(1000.0);
});

test('the buyers tab shows the wallet buyer as sold, handed over and still held', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer(1000);
    handOver($buyer, 400);

    $row = Volt::test('egg-sales.buyer-manager')
        ->assertSee('Cash wallet')
        ->assertSee('600.00')
        ->viewData('buyers')
        ->firstWhere('id', $buyer->id);

    expect((float) $row->handed_total)->toBe(400.0);
});

test('editing the wallet buyer on the buyers tab keeps the wallet', function () {
    $this->actingAs(User::factory()->create());
    $buyer = walletBuyer();

    Volt::test('egg-sales.buyer-manager')
        ->call('startEdit', $buyer->id)
        ->set('phone', '01711000000')
        ->call('save')
        ->assertHasNoErrors();

    expect($buyer->fresh()->uses_cash_wallet)->toBeTrue();
    expect($buyer->fresh()->phone)->toBe('01711000000');
});

test('view-only staff can read the wallet but change nothing', function () {
    $buyer = walletBuyer(1000);
    $handover = handOver($buyer, 100);
    $this->actingAs(staffWithWalletPermissions(Permission::EggSalesView));

    Volt::test('egg-sales.cash-wallet')
        ->assertSee('Still with Mr.Akter')
        ->assertDontSee('+ Handed to Office')
        ->assertDontSeeHtml('wire:click="startEdit');

    Volt::test('egg-sales.cash-wallet')->set('amount', '50')->set('handed_on', '2026-10-01')->call('save')->assertForbidden();
    Volt::test('egg-sales.cash-wallet')->call('confirmDelete', $handover->id)->call('delete')->assertForbidden();
    expect(EggCashHandover::count())->toBe(1);
});

test('add-only staff can record a handover but not edit or delete one', function () {
    $buyer = walletBuyer(1000);
    $handover = handOver($buyer, 100);
    $this->actingAs(staffWithWalletPermissions(Permission::EggSalesView, Permission::EggSalesCreate));

    Volt::test('egg-sales.cash-wallet')
        ->assertSee('+ Handed to Office')
        ->assertDontSeeHtml('wire:click="startEdit')
        ->call('startCreate')
        ->set('amount', '50')
        ->call('save')
        ->assertHasNoErrors();
    expect(EggCashHandover::count())->toBe(2);

    Volt::test('egg-sales.cash-wallet')
        ->call('startEdit', $handover->id)
        ->set('amount', '10')
        ->call('save')
        ->assertForbidden();
    expect((float) $handover->fresh()->amount)->toBe(100.0);
});
