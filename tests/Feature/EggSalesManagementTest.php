<?php

use App\Models\EggBuyer;
use App\Models\EggBuyerPayment;
use App\Models\EggInCharge;
use App\Models\EggInChargeTransaction;
use App\Models\EggSale;
use App\Models\RolePermission;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/egg-sales')->assertRedirect('/login');
});

test('a sale remarks is shown on its row in the sales list', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->set('saleYearFilter', '')
        ->call('startCreateSale')
        ->set('sale_date', '2026-09-05')
        ->set('sale_quantity', '200')
        ->set('sale_rate', '14')
        ->set('sale_remarks', 'Buyer picked up in person')
        ->call('saveSale')
        ->assertHasNoErrors()
        ->assertSee('Buyer picked up in person');
});

test('a sale defaults to cash and can be recorded as due instead', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->assertSet('payment_status', 'cash')
        ->set('sale_date', '2026-09-05')
        ->set('sale_quantity', '200')
        ->set('sale_rate', '14')
        ->set('payment_status', 'due')
        ->call('saveSale')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('egg_sales', [
        'quantity' => 200,
        'payment_status' => 'due',
    ]);
});

test('a sale in-charge is shown on its row and the in-charge filter narrows the list', function () {
    $user = User::factory()->create();
    $karim = EggInCharge::factory()->create(['name' => 'Mr. Karim']);
    $rahim = EggInCharge::factory()->create(['name' => 'Mr. Rahim']);
    EggSale::create([
        'sale_date' => '2026-09-05',
        'quantity' => 200,
        'sale_rate' => 14,
        'sale_amount' => 2800,
        'egg_in_charge_id' => $karim->id,
    ]);
    EggSale::create([
        'sale_date' => '2026-09-06',
        'quantity' => 100,
        'sale_rate' => 14,
        'sale_amount' => 1400,
        'egg_in_charge_id' => $rahim->id,
    ]);

    $this->actingAs($user);

    $component = Volt::test('egg-sales.sales-manager')
        ->set('saleYearFilter', '')
        ->assertSee('Mr. Karim')
        ->assertSee('Mr. Rahim')
        ->set('saleInChargeFilter', (string) $karim->id);

    // Mr. Rahim still appears as an <option> in the In-Charge filter
    // dropdown itself (it always lists every In-Charge person), so the
    // meaningful assertion is the sales list's own content, not the whole
    // page — same pattern as the buyer filter test above.
    expect($component->viewData('sales'))->toHaveCount(1);
    expect($component->viewData('sales')->first()->egg_in_charge_id)->toBe($karim->id);
});

test('a new in-charge person can be created inline from the sale form and is selected immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('showNewInChargeFields', true)
        ->set('new_in_charge_name', 'Mr. Akter')
        ->set('new_in_charge_phone', '01700000000')
        ->call('addInChargeInline')
        ->assertHasNoErrors()
        ->assertSet('showNewInChargeFields', false);

    $inCharge = EggInCharge::where('name', 'Mr. Akter')->sole();
    expect($inCharge->phone)->toBe('01700000000');
});

test('an in-charge person can be renamed inline from the sale form', function () {
    $user = User::factory()->create();
    $inCharge = EggInCharge::factory()->create(['name' => 'Aktar', 'phone' => null]);
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('egg_in_charge_id', $inCharge->id)
        ->call('startEditInChargeInline')
        ->assertSet('edit_in_charge_name', 'Aktar')
        ->set('edit_in_charge_name', 'Mr. Akter')
        ->call('saveInChargeInline')
        ->assertHasNoErrors()
        ->assertSet('showEditInChargeFields', false);

    expect($inCharge->fresh()->name)->toBe('Mr. Akter');
});

test('a duplicate in-charge name is rejected when creating inline', function () {
    $user = User::factory()->create();
    EggInCharge::factory()->create(['name' => 'Mr. Akter']);
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('showNewInChargeFields', true)
        ->set('new_in_charge_name', 'Mr. Akter')
        ->call('addInChargeInline')
        ->assertHasErrors(['new_in_charge_name']);
});

test('recording a sale with an in-charge person persists the link', function () {
    $user = User::factory()->create();
    $akter = EggInCharge::factory()->create(['name' => 'Mr. Akter']);
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('sale_date', '2026-09-05')
        ->set('sale_quantity', '200')
        ->set('sale_rate', '14')
        ->set('egg_in_charge_id', $akter->id)
        ->call('saveSale')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('egg_sales', [
        'quantity' => 200,
        'egg_in_charge_id' => $akter->id,
    ]);
});

test('the in-charge ledger counts Due sales toward total sales and what they owe, same as Cash ones', function () {
    $user = User::factory()->create();
    $akter = EggInCharge::factory()->create(['name' => 'Mr. Akter']);
    EggSale::create([
        'sale_date' => '2026-09-05', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400,
        'egg_in_charge_id' => $akter->id, 'payment_status' => 'cash',
    ]);
    EggSale::create([
        'sale_date' => '2026-09-06', 'quantity' => 50, 'sale_rate' => 14, 'sale_amount' => 700,
        'egg_in_charge_id' => $akter->id, 'payment_status' => 'due',
    ]);

    $this->actingAs($user);

    $summaries = Volt::test('egg-sales.sales-manager')
        ->set('inChargeSummaryYearFilter', '')
        ->call('switchView', 'in-charge')
        ->viewData('inChargeSummaries');

    $akterSummary = $summaries->firstWhere('inChargeId', $akter->id);
    expect($akterSummary->cashAmount)->toBe(1400.0);
    expect($akterSummary->dueAmount)->toBe(700.0);
    expect($akterSummary->totalSales)->toBe(2100.0);
    // An In-Charge person owns their whole operation, Cash and Due alike —
    // the Due sale is still their responsibility to account for, same as
    // Mr. Akter's real, entirely-Due sales history.
    expect($akterSummary->outstandingOwed)->toBe(2100.0);
});

test('recording an expense reduces what an in-charge person owes overall', function () {
    $user = User::factory()->create();
    $akter = EggInCharge::factory()->create(['name' => 'Mr. Akter']);
    EggSale::create([
        'sale_date' => '2026-09-05', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400,
        'egg_in_charge_id' => $akter->id, 'payment_status' => 'cash',
    ]);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordInChargeTransaction', $akter->id)
        ->set('transactionType', 'expense')
        ->set('transactionAmount', '400')
        ->set('transactionDate', '2026-09-06')
        ->set('transactionDescription', 'Egg crate repair')
        ->call('recordInChargeTransaction')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('egg_in_charge_transactions', [
        'egg_in_charge_id' => $akter->id,
        'type' => EggInChargeTransaction::TYPE_EXPENSE,
        'amount' => 400,
        'description' => 'Egg crate repair',
    ]);
    expect($akter->fresh()->outstandingOwed)->toBe(1000.0);
});

test('recording a remittance reduces what an in-charge person owes overall, same as an expense', function () {
    $user = User::factory()->create();
    $akter = EggInCharge::factory()->create(['name' => 'Mr. Akter']);
    EggSale::create([
        'sale_date' => '2026-09-05', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400,
        'egg_in_charge_id' => $akter->id, 'payment_status' => 'cash',
    ]);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordInChargeTransaction', $akter->id)
        ->set('transactionType', 'remittance')
        ->set('transactionAmount', '1400')
        ->set('transactionDate', '2026-09-10')
        ->set('transactionDescription', 'Handed to office')
        ->call('recordInChargeTransaction')
        ->assertHasNoErrors();

    expect($akter->fresh()->outstandingOwed)->toBe(0.0);
});

test('a description is required to record an in-charge transaction', function () {
    $user = User::factory()->create();
    $akter = EggInCharge::factory()->create();
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordInChargeTransaction', $akter->id)
        ->set('transactionAmount', '400')
        ->set('transactionDate', '2026-09-06')
        ->call('recordInChargeTransaction')
        ->assertHasErrors(['transactionDescription']);
});

test('deleting an in-charge transaction restores the outstanding balance', function () {
    $user = User::factory()->create();
    $akter = EggInCharge::factory()->create();
    EggSale::create([
        'sale_date' => '2026-09-05', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400,
        'egg_in_charge_id' => $akter->id, 'payment_status' => 'cash',
    ]);
    $transaction = EggInChargeTransaction::create([
        'egg_in_charge_id' => $akter->id,
        'type' => EggInChargeTransaction::TYPE_EXPENSE,
        'transaction_date' => '2026-09-06',
        'amount' => 400,
        'description' => 'Egg crate repair',
    ]);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDeleteInChargeTransaction', $transaction->id)
        ->call('deleteInChargeTransaction');

    $this->assertDatabaseMissing('egg_in_charge_transactions', ['id' => $transaction->id]);
    expect($akter->fresh()->outstandingOwed)->toBe(1400.0);
});

test('viewing an in-charge person from the ledger jumps to the Sales tab filtered to them', function () {
    $user = User::factory()->create();
    $akter = EggInCharge::factory()->create(['name' => 'Mr. Akter']);
    EggSale::create([
        'sale_date' => '2026-09-05', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400,
        'egg_in_charge_id' => $akter->id, 'payment_status' => 'cash',
    ]);

    $this->actingAs($user);

    $component = Volt::test('egg-sales.sales-manager')
        ->set('inChargeSummaryYearFilter', '')
        ->call('switchView', 'in-charge')
        ->call('viewInChargeSales', $akter->id)
        ->assertSet('activeView', 'sales')
        ->assertSet('saleInChargeFilter', (string) $akter->id);

    expect($component->viewData('sales'))->toHaveCount(1);
});

test('an accountant gets a 403 recording or removing an in-charge transaction', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::EggSalesCreate->value]);
    $akter = EggInCharge::factory()->create();

    $this->actingAs($accountant);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordInChargeTransaction', $akter->id)
        ->set('transactionAmount', '200')
        ->set('transactionDate', '2026-09-10')
        ->set('transactionDescription', 'Fuel')
        ->call('recordInChargeTransaction')
        ->assertForbidden();

    $transaction = EggInChargeTransaction::create([
        'egg_in_charge_id' => $akter->id,
        'type' => EggInChargeTransaction::TYPE_EXPENSE,
        'transaction_date' => '2026-09-10',
        'amount' => 100,
        'description' => 'Fuel',
    ]);

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDeleteInChargeTransaction', $transaction->id)
        ->call('deleteInChargeTransaction')
        ->assertForbidden();
});

test('a buyer filter narrows the listed sales', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    $rahim = EggBuyer::factory()->create(['name' => 'Rahim Store']);
    EggSale::create(['sale_date' => '2026-09-05', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400, 'egg_buyer_id' => $karim->id]);
    EggSale::create(['sale_date' => '2026-09-06', 'quantity' => 50, 'sale_rate' => 14, 'sale_amount' => 700, 'egg_buyer_id' => $rahim->id]);

    $this->actingAs($user);

    $component = Volt::test('egg-sales.sales-manager')->set('saleYearFilter', '')->set('saleBuyerFilter', (string) $karim->id);

    // Rahim Store still appears as an <option> in the buyer filter dropdown
    // itself (it always lists every buyer), so the meaningful assertion is
    // the sales list's own content, not the whole page.
    expect($component->viewData('sales'))->toHaveCount(1);
    expect($component->viewData('sales')->first()->egg_buyer_id)->toBe($karim->id);
});

test('a new buyer can be created inline from the sale form and is selected immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('showNewBuyerFields', true)
        ->set('new_buyer_name', 'Karim Traders')
        ->set('new_buyer_phone', '01700000000')
        ->call('addBuyerInline')
        ->assertHasNoErrors()
        ->assertSet('showNewBuyerFields', false);

    $buyer = EggBuyer::where('name', 'Karim Traders')->sole();
    expect($buyer->phone)->toBe('01700000000');
});

test('a buyer can be renamed inline from the sale form', function () {
    $user = User::factory()->create();
    $buyer = EggBuyer::factory()->create(['name' => 'Karim Traders', 'phone' => null]);
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('egg_buyer_id', $buyer->id)
        ->call('startEditBuyerInline')
        ->assertSet('edit_buyer_name', 'Karim Traders')
        ->set('edit_buyer_name', 'Karim Traders Ltd')
        ->set('edit_buyer_phone', '01700000000')
        ->call('saveBuyerInline')
        ->assertHasNoErrors()
        ->assertSet('showEditBuyerFields', false);

    expect($buyer->fresh()->name)->toBe('Karim Traders Ltd');
    expect($buyer->fresh()->phone)->toBe('01700000000');
});

test('renaming a buyer to another buyer\'s existing name is rejected', function () {
    $user = User::factory()->create();
    EggBuyer::factory()->create(['name' => 'Rahim Store']);
    $buyer = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('egg_buyer_id', $buyer->id)
        ->call('startEditBuyerInline')
        ->set('edit_buyer_name', 'Rahim Store')
        ->call('saveBuyerInline')
        ->assertHasErrors(['edit_buyer_name']);

    expect($buyer->fresh()->name)->toBe('Karim Traders');
});

test('an accountant can rename a buyer via the sale form', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::EggSalesCreate->value]);
    $buyer = EggBuyer::factory()->create(['name' => 'Karim Traders']);

    $this->actingAs($accountant);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('egg_buyer_id', $buyer->id)
        ->call('startEditBuyerInline')
        ->set('edit_buyer_name', 'Karim Traders Ltd')
        ->call('saveBuyerInline')
        ->assertHasNoErrors();

    expect($buyer->fresh()->name)->toBe('Karim Traders Ltd');
});

test('a duplicate buyer name is rejected when creating inline', function () {
    $user = User::factory()->create();
    EggBuyer::factory()->create(['name' => 'Karim Traders']);
    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('showNewBuyerFields', true)
        ->set('new_buyer_name', 'Karim Traders')
        ->call('addBuyerInline')
        ->assertHasErrors(['new_buyer_name']);
});

test('the buyer summary groups sales by buyer with a cash/due/paid split', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    $rahim = EggBuyer::factory()->create(['name' => 'Rahim Store']);
    EggSale::create([
        'sale_date' => '2026-09-05', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400,
        'egg_buyer_id' => $karim->id, 'payment_status' => 'cash',
    ]);
    EggSale::create([
        'sale_date' => '2026-09-10', 'quantity' => 50, 'sale_rate' => 14, 'sale_amount' => 700,
        'egg_buyer_id' => $karim->id, 'payment_status' => 'due',
    ]);
    EggSale::create([
        'sale_date' => '2026-09-12', 'quantity' => 30, 'sale_rate' => 14, 'sale_amount' => 420,
        'egg_buyer_id' => $rahim->id, 'payment_status' => 'paid',
    ]);

    $this->actingAs($user);

    $summaries = Volt::test('egg-sales.sales-manager')
        ->set('summaryYearFilter', '')
        ->call('switchView', 'buyer-summary')
        ->assertSee('Karim Traders')
        ->assertSee('Rahim Store')
        ->viewData('buyerSummaries');

    expect($summaries)->toHaveCount(2);

    $karimSummary = $summaries->firstWhere('buyerName', 'Karim Traders');
    expect($karimSummary->buyerId)->toBe($karim->id);
    expect($karimSummary->quantity)->toBe(150.0);
    expect($karimSummary->cashAmount)->toBe(1400.0);
    expect($karimSummary->dueAmount)->toBe(700.0);
    expect($karimSummary->totalAmount)->toBe(2100.0);

    $rahimSummary = $summaries->firstWhere('buyerName', 'Rahim Store');
    expect($rahimSummary->paidAmount)->toBe(420.0);
    expect($rahimSummary->totalAmount)->toBe(420.0);
});

test('the buyer summary year and month filters narrow which sales are grouped', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    EggSale::create(['sale_date' => '2025-08-10', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400, 'egg_buyer_id' => $karim->id]);
    EggSale::create(['sale_date' => '2026-03-05', 'quantity' => 50, 'sale_rate' => 14, 'sale_amount' => 700, 'egg_buyer_id' => $karim->id]);

    $this->actingAs($user);

    $byYear = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2025');
    expect($byYear->viewData('buyerSummaries')->first()->quantity)->toBe(100.0);

    $byMonth = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2026')
        ->set('summaryMonthFilter', '3');
    expect($byMonth->viewData('buyerSummaries')->first()->quantity)->toBe(50.0);
});

test('the buyer summary labels which period the Received figure is for, but shows no label when unfiltered', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create();
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_date' => '2026-09-05']);
    $this->actingAs($user);

    $allTime = Volt::test('egg-sales.sales-manager')->set('summaryYearFilter', '')->call('switchView', 'buyer-summary');
    expect($allTime->viewData('summaryPeriodLabel'))->toBeNull();
    $allTime->assertDontSee('All Time');

    $yearOnly = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2026');
    expect($yearOnly->viewData('summaryPeriodLabel'))->toBe('2026');

    $yearAndMonth = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2026')
        ->set('summaryMonthFilter', '9')
        ->assertSee('September 2026');
    expect($yearAndMonth->viewData('summaryPeriodLabel'))->toBe('September 2026');
});

test('viewing a buyer from the summary jumps to the Sales tab filtered to that buyer and period', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    EggSale::create(['sale_date' => '2026-03-05', 'quantity' => 50, 'sale_rate' => 14, 'sale_amount' => 700, 'egg_buyer_id' => $karim->id]);

    $this->actingAs($user);

    $component = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2026')
        ->set('summaryMonthFilter', '3')
        ->call('viewBuyerSales', $karim->id)
        ->assertSet('activeView', 'sales')
        ->assertSet('saleBuyerFilter', (string) $karim->id)
        ->assertSet('saleYearFilter', '2026')
        ->assertSet('saleMonthFilter', '3');

    expect($component->viewData('sales'))->toHaveCount(1);
});

test('recording a partial payment reduces a buyer\'s outstanding balance without touching their due sales', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create();
    $sale = EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_amount' => 1000, 'payment_status' => 'due']);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordBuyerPayment', $karim->id)
        ->assertSet('buyerPayAmount', '1000')
        ->set('buyerPayAmount', '400')
        ->set('buyerPayDate', '2026-09-10')
        ->set('buyerPayRemarks', 'Partial payment')
        ->call('recordBuyerPayment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('egg_buyer_payments', [
        'egg_buyer_id' => $karim->id,
        'amount' => 400,
        'remarks' => 'Partial payment',
    ]);
    expect($karim->fresh()->outstandingDue)->toBe(600.0);
    // Recording a payment never flips the underlying sale's own status —
    // it's tracked as a separate running balance, not per-sale.
    expect($sale->fresh()->payment_status)->toBe('due');
});

test('a second payment can finish settling what the first partial payment left outstanding', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create();
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_amount' => 1000, 'payment_status' => 'due']);
    EggBuyerPayment::create(['egg_buyer_id' => $karim->id, 'amount' => 400, 'paid_on' => '2026-09-10']);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordBuyerPayment', $karim->id)
        ->assertSet('buyerPayAmount', '600')
        ->call('recordBuyerPayment')
        ->assertHasNoErrors();

    expect($karim->fresh()->outstandingDue)->toBe(0.0);
});

test('a payment cannot exceed what the buyer currently owes', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create();
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_amount' => 500, 'payment_status' => 'due']);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordBuyerPayment', $karim->id)
        ->set('buyerPayAmount', '600')
        ->set('buyerPayDate', '2026-09-10')
        ->call('recordBuyerPayment')
        ->assertHasErrors(['buyerPayAmount']);

    expect($karim->fresh()->outstandingDue)->toBe(500.0);
});

test('the buyer summary shows what a buyer owes overall and offers Record Payment only while something is outstanding', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_date' => '2026-09-05', 'sale_amount' => 1000, 'payment_status' => 'due']);
    EggBuyerPayment::create(['egg_buyer_id' => $karim->id, 'amount' => 1000, 'paid_on' => '2026-09-06']);

    $this->actingAs($user);

    $summaries = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2026')
        ->set('summaryMonthFilter', '9')
        // The modal's own title also reads "Record Payment", so this checks
        // for the button's wire:click call specifically, not just the text.
        ->assertDontSee("startRecordBuyerPayment({$karim->id})", false)
        ->viewData('buyerSummaries');

    $karimSummary = $summaries->firstWhere('buyerName', 'Karim Traders');
    expect($karimSummary->outstandingDue)->toBe(0.0);
    // The client collects payment from buyers every month, so the amount
    // actually received this period must be visible in the table too.
    expect($karimSummary->paidAmount)->toBe(1000.0);
});

test('a payment received this period only counts toward this period, not a different month', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_date' => '2026-09-05', 'sale_amount' => 1000, 'payment_status' => 'due']);
    EggBuyerPayment::create(['egg_buyer_id' => $karim->id, 'amount' => 300, 'paid_on' => '2026-09-10']);
    EggBuyerPayment::create(['egg_buyer_id' => $karim->id, 'amount' => 200, 'paid_on' => '2026-08-15']);

    $this->actingAs($user);

    $september = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2026')
        ->set('summaryMonthFilter', '9')
        ->viewData('buyerSummaries')
        ->firstWhere('buyerName', 'Karim Traders');

    expect($september->paidAmount)->toBe(300.0);
    // outstandingDue is deliberately all-time, so it reflects both payments
    // regardless of which month is being viewed.
    expect($september->outstandingDue)->toBe(500.0);
});

test('a buyer who only pays this period, with no new sale, still gets their own row', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create(['name' => 'Karim Traders']);
    // Sold last month, nothing new this month — just a payment coming in
    // against last month's due balance.
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_date' => '2026-08-05', 'sale_amount' => 1000, 'payment_status' => 'due']);
    EggBuyerPayment::create(['egg_buyer_id' => $karim->id, 'amount' => 400, 'paid_on' => '2026-09-10']);

    $this->actingAs($user);

    $summaries = Volt::test('egg-sales.sales-manager')
        ->call('switchView', 'buyer-summary')
        ->set('summaryYearFilter', '2026')
        ->set('summaryMonthFilter', '9')
        ->viewData('buyerSummaries');

    $karimSummary = $summaries->firstWhere('buyerName', 'Karim Traders');
    expect($karimSummary)->not->toBeNull();
    expect($karimSummary->quantity)->toBe(0.0);
    expect($karimSummary->dueAmount)->toBe(0.0);
    expect($karimSummary->paidAmount)->toBe(400.0);
    expect($karimSummary->outstandingDue)->toBe(600.0);
});

test('deleting a payment restores the buyer\'s outstanding balance', function () {
    $user = User::factory()->create();
    $karim = EggBuyer::factory()->create();
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_amount' => 1000, 'payment_status' => 'due']);
    $payment = EggBuyerPayment::create(['egg_buyer_id' => $karim->id, 'amount' => 400, 'paid_on' => '2026-09-10']);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDeleteBuyerPayment', $payment->id)
        ->call('deleteBuyerPayment');

    $this->assertDatabaseMissing('egg_buyer_payments', ['id' => $payment->id]);
    expect($karim->fresh()->outstandingDue)->toBe(1000.0);
});

test('an accountant gets a 403 recording or removing a buyer payment', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::EggSalesCreate->value]);
    $karim = EggBuyer::factory()->create();
    EggSale::factory()->create(['egg_buyer_id' => $karim->id, 'sale_amount' => 500, 'payment_status' => 'due']);

    $this->actingAs($accountant);

    Volt::test('egg-sales.sales-manager')
        ->call('startRecordBuyerPayment', $karim->id)
        ->set('buyerPayAmount', '200')
        ->set('buyerPayDate', '2026-09-10')
        ->call('recordBuyerPayment')
        ->assertForbidden();

    $payment = EggBuyerPayment::create(['egg_buyer_id' => $karim->id, 'amount' => 100, 'paid_on' => '2026-09-10']);

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDeleteBuyerPayment', $payment->id)
        ->call('deleteBuyerPayment')
        ->assertForbidden();
});

test('recording a sale persists with a computed sale amount and reduces stock on hand', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);
    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 1000,
        'purchase_rate' => 11.5,
        'purchase_amount' => 11500,
    ]);
    $buyer = EggBuyer::factory()->create(['name' => 'Local Market']);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('sale_date', '2026-09-05')
        ->set('sale_quantity', '200')
        ->set('sale_rate', '14')
        ->set('egg_buyer_id', $buyer->id)
        ->call('saveSale')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('egg_sales', [
        'quantity' => 200,
        'sale_rate' => 14,
        'sale_amount' => 2800,
        'egg_buyer_id' => $buyer->id,
    ]);

    // Stock still lives on the Egg Purchases page — 1000 purchased - 0
    // consumed - 200 sold = 800.
    $component = Volt::test('egg-purchases.purchase-manager');
    expect($component->viewData('eggInStock'))->toBe(800.0);
});

test('editing a sale updates it', function () {
    $user = User::factory()->create();
    $sale = EggSale::create([
        'sale_date' => '2026-09-02',
        'quantity' => 200,
        'sale_rate' => 14,
        'sale_amount' => 2800,
    ]);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startEditSale', $sale->id)
        ->set('sale_quantity', '250')
        ->set('sale_rate', '15')
        ->call('saveSale')
        ->assertHasNoErrors();

    expect((float) $sale->fresh()->quantity)->toBe(250.0);
    expect((float) $sale->fresh()->sale_rate)->toBe(15.0);
    expect((float) $sale->fresh()->sale_amount)->toBe(3750.0);
});

test('deleting a sale removes it', function () {
    $user = User::factory()->create();
    $sale = EggSale::create([
        'sale_date' => '2026-09-02',
        'quantity' => 200,
        'sale_rate' => 14,
        'sale_amount' => 2800,
    ]);

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDeleteSale', $sale->id)
        ->call('deleteSale');

    $this->assertDatabaseMissing('egg_sales', ['id' => $sale->id]);
});

test('quantity and sale rate are required to record a sale', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('sale_date', '2026-09-05')
        ->call('saveSale')
        ->assertHasErrors(['sale_quantity', 'sale_rate']);
});

test('year and month filters narrow the listed sales', function () {
    $user = User::factory()->create();

    EggSale::create(['sale_date' => '2025-08-10', 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400]);
    EggSale::create(['sale_date' => '2026-03-05', 'quantity' => 120, 'sale_rate' => 14, 'sale_amount' => 1680]);

    $this->actingAs($user);

    $byYear = Volt::test('egg-sales.sales-manager')->set('saleYearFilter', '2025');
    expect($byYear->viewData('sales'))->toHaveCount(1);

    $byMonth = Volt::test('egg-sales.sales-manager')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '3');
    expect($byMonth->viewData('sales'))->toHaveCount(1);

    $wrongMonth = Volt::test('egg-sales.sales-manager')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '4');
    expect($wrongMonth->viewData('sales'))->toHaveCount(0);
});

test('changing the sale year clears an incompatible month selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '3')
        ->set('saleYearFilter', '2025')
        ->assertSet('saleMonthFilter', '');
});

test('the sale quick range filter only counts sales within that window', function () {
    $user = User::factory()->create();

    EggSale::create(['sale_date' => now()->subDays(3)->toDateString(), 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400]);
    EggSale::create(['sale_date' => now()->subDays(20)->toDateString(), 'quantity' => 200, 'sale_rate' => 14, 'sale_amount' => 2800]);
    EggSale::create(['sale_date' => now()->subDays(25)->toDateString(), 'quantity' => 150, 'sale_rate' => 14, 'sale_amount' => 2100]);

    $this->actingAs($user);

    $byWeek = Volt::test('egg-sales.sales-manager')->set('saleRangeFilter', '7');
    expect($byWeek->viewData('sales'))->toHaveCount(1);

    $byFifteenDays = Volt::test('egg-sales.sales-manager')->set('saleRangeFilter', '15');
    expect($byFifteenDays->viewData('sales'))->toHaveCount(1);

    $byThirtyDays = Volt::test('egg-sales.sales-manager')->set('saleRangeFilter', '30');
    expect($byThirtyDays->viewData('sales'))->toHaveCount(3);
});

test('choosing a sale range clears the year and month, and vice versa', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-sales.sales-manager')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '3')
        ->set('saleRangeFilter', '7')
        ->assertSet('saleYearFilter', '')
        ->assertSet('saleMonthFilter', '')
        ->set('saleYearFilter', '2025')
        ->assertSet('saleRangeFilter', '');
});

test('an accountant can record a sale but gets a 403 trying to edit or delete one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::EggSalesCreate->value]);
    $sale = EggSale::create([
        'sale_date' => '2026-09-01',
        'quantity' => 200,
        'sale_rate' => 14,
        'sale_amount' => 2800,
    ]);

    $this->actingAs($accountant);

    Volt::test('egg-sales.sales-manager')
        ->call('startCreateSale')
        ->set('sale_date', '2026-09-05')
        ->set('sale_quantity', '100')
        ->set('sale_rate', '14')
        ->call('saveSale')
        ->assertHasNoErrors();

    Volt::test('egg-sales.sales-manager')
        ->call('startEditSale', $sale->id)
        ->set('sale_quantity', '999')
        ->call('saveSale')
        ->assertForbidden();

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDeleteSale', $sale->id)
        ->call('deleteSale')
        ->assertForbidden();
});
