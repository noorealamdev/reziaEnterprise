<?php

use App\Models\Company;
use App\Models\EggSale;
use App\Models\JobEntry;
use App\Models\RolePermission;
use App\Models\TiffinDepartment;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('the stock summary nets purchases minus tiffin consumption minus external sales', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $egg = TiffinItem::create(['name' => 'Egg']);

    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 1000,
        'cost_rate' => 11.5,
        'cost_amount' => 11500,
    ]);

    // Tiffin's own internal use — 300 eggs sent out (headcount + buffer).
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-09-02',
        'supply_type' => 'Egg',
        'quantity' => 300,
    ]);

    // A non-Tiffin job entry that happens to also be named "Egg" must
    // never be pulled into the stock ledger.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => null,
        'entry_date' => '2026-09-02',
        'supply_type' => 'Egg',
        'quantity' => 99999,
    ]);

    EggSale::create([
        'sale_date' => '2026-09-03',
        'quantity' => 150,
        'sale_rate' => 14,
        'sale_amount' => 2100,
        'buyer_name' => 'Local Market',
    ]);

    $this->actingAs($user);

    $component = Volt::test('egg-purchases.purchase-manager');

    expect($component->viewData('eggTotalPurchased'))->toBe(1000.0);
    expect($component->viewData('eggTotalConsumed'))->toBe(300.0);
    expect($component->viewData('eggTotalSold'))->toBe(150.0);
    // 1000 - 300 - 150 = 550.
    expect($component->viewData('eggInStock'))->toBe(550.0);
    expect($component->viewData('eggTotalRevenue'))->toBe(2100.0);
});

test('saving a new Tiffin batch entry immediately reduces In Stock Now on the Egg Purchases page', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $company->serviceCategories()->attach($tiffin);
    $company->tiffinDepartments()->attach($swing);
    makeTiffinRecipe($swing, ['Egg']);

    $egg = TiffinItem::where('name', 'Egg')->firstOrFail();
    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => now()->toDateString(),
        'quantity' => 1000,
        'cost_rate' => 11.5,
        'cost_amount' => 11500,
    ]);

    $this->actingAs($user);

    // Before adding the new Tiffin entry, all 1,000 purchased eggs are
    // still in stock.
    expect(Volt::test('egg-purchases.purchase-manager')->viewData('eggInStock'))->toBe(1000.0);

    // Add a brand new Tiffin/Egg batch entry through the real form — the
    // same action as "adding a new Tiffin entry for a company."
    Volt::test('job-entries.job-entry-form')
        ->set('company_id', $company->id)
        ->set('entry_date', now()->toDateString())
        ->set('service_category_id', $tiffin->id)
        ->set("batchQuantities.{$swing->id}.Egg", '200')
        ->set("batchCostRates.{$swing->id}.Egg", '11.5')
        ->set("batchBillRates.{$swing->id}.Egg", '30')
        ->call('saveTiffinItemBatch')
        ->assertHasNoErrors();

    // 200 headcount + the fixed 5-egg buffer = 205 eggs actually
    // consumed, so stock drops from 1,000 to 795 — deducted purely from
    // the purchased stock, with no separate action needed.
    expect(Volt::test('egg-purchases.purchase-manager')->viewData('eggInStock'))->toBe(795.0);
});

test('recording a sale persists with a computed sale amount and reduces stock on hand', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);
    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 1000,
        'cost_rate' => 11.5,
        'cost_amount' => 11500,
    ]);

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
        ->call('startCreateSale')
        ->set('sale_date', '2026-09-05')
        ->set('sale_quantity', '200')
        ->set('sale_rate', '14')
        ->set('buyer_name', 'Local Market')
        ->call('saveSale')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('egg_sales', [
        'quantity' => 200,
        'sale_rate' => 14,
        'sale_amount' => 2800,
        'buyer_name' => 'Local Market',
    ]);

    $component = Volt::test('egg-purchases.purchase-manager');
    // 1000 purchased - 0 consumed - 200 sold = 800.
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

    Volt::test('egg-purchases.purchase-manager')
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

    Volt::test('egg-purchases.purchase-manager')
        ->call('confirmDeleteSale', $sale->id)
        ->call('deleteSale');

    $this->assertDatabaseMissing('egg_sales', ['id' => $sale->id]);
});

test('quantity and sale rate are required to record a sale', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
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

    $byYear = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'sales')
        ->set('saleYearFilter', '2025');
    expect($byYear->viewData('sales'))->toHaveCount(1);

    $byMonth = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'sales')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '3');
    expect($byMonth->viewData('sales'))->toHaveCount(1);

    $wrongMonth = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'sales')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '4');
    expect($wrongMonth->viewData('sales'))->toHaveCount(0);
});

test('changing the sale year clears an incompatible month selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '3')
        ->set('saleYearFilter', '2025')
        ->assertSet('saleMonthFilter', '');
});

test('the sale quick range filter only counts sales within that window', function () {
    $user = User::factory()->create();

    EggSale::create(['sale_date' => now()->subDays(3)->toDateString(), 'quantity' => 100, 'sale_rate' => 14, 'sale_amount' => 1400]);
    EggSale::create(['sale_date' => now()->subDays(20)->toDateString(), 'quantity' => 200, 'sale_rate' => 14, 'sale_amount' => 2800]);

    $this->actingAs($user);

    $byWeek = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'sales')
        ->set('saleRangeFilter', '7');
    expect($byWeek->viewData('sales'))->toHaveCount(1);

    $byFifteenDays = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'sales')
        ->set('saleRangeFilter', '15');
    expect($byFifteenDays->viewData('sales'))->toHaveCount(1);
});

test('choosing a sale range clears the year and month, and vice versa', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
        ->set('saleYearFilter', '2026')
        ->set('saleMonthFilter', '3')
        ->set('saleRangeFilter', '7')
        ->assertSet('saleYearFilter', '')
        ->assertSet('saleMonthFilter', '')
        ->set('saleYearFilter', '2025')
        ->assertSet('saleRangeFilter', '');
});

test('a user without egg sales permission cannot see the sales tab or stock summary', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::EggPurchasesView->value]);

    $this->actingAs($staff);

    Volt::test('egg-purchases.purchase-manager')
        ->assertDontSee('Sales')
        ->assertDontSee('Sold Externally');
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

    Volt::test('egg-purchases.purchase-manager')
        ->call('startCreateSale')
        ->set('sale_date', '2026-09-05')
        ->set('sale_quantity', '100')
        ->set('sale_rate', '14')
        ->call('saveSale')
        ->assertHasNoErrors();

    Volt::test('egg-purchases.purchase-manager')
        ->call('startEditSale', $sale->id)
        ->set('sale_quantity', '999')
        ->call('saveSale')
        ->assertForbidden();

    Volt::test('egg-purchases.purchase-manager')
        ->call('confirmDeleteSale', $sale->id)
        ->call('deleteSale')
        ->assertForbidden();
});
