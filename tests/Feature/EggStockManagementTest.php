<?php

use App\Models\Company;
use App\Models\EggSale;
use App\Models\EggWaste;
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
        'purchase_rate' => 11.5,
        'purchase_amount' => 11500,
        'sale_rate' => 11.5,
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
    ]);

    EggWaste::create([
        'waste_date' => '2026-09-04',
        'quantity' => 20,
        'remarks' => 'Crate dropped in storage',
    ]);

    $this->actingAs($user);

    $component = Volt::test('egg-purchases.purchase-manager');

    expect($component->viewData('eggTotalPurchased'))->toBe(1000.0);
    expect($component->viewData('eggTotalConsumed'))->toBe(300.0);
    expect($component->viewData('eggTotalSold'))->toBe(150.0);
    expect($component->viewData('eggTotalWasted'))->toBe(20.0);
    // 1000 - 300 - 150 - 20 = 530.
    expect($component->viewData('eggInStock'))->toBe(530.0);
    expect($component->viewData('eggTotalRevenue'))->toBe(2100.0);
});

test('egg profit counts both Tiffin\'s internal use and external sales against the real purchase cost', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $egg = TiffinItem::create(['name' => 'Egg']);

    // 1000 bought at 10 each = 10,000 spent, locked to sell internally at 12.
    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 1000,
        'purchase_rate' => 10,
        'purchase_amount' => 10000,
        'sale_rate' => 12,
    ]);

    // Tiffin "pays" 300 * 12 = 3,600 — this is revenue to the egg business,
    // not a cost, even though it never leaves the company's bank account.
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-09-02',
        'supply_type' => 'Egg',
        'quantity' => 300,
        'cost_rate' => 12,
        'cost_amount' => 3600,
    ]);

    // An outside buyer pays 150 * 14 = 2,100 — a completely different rate
    // than Tiffin's, and must still count toward total profit.
    EggSale::create([
        'sale_date' => '2026-09-03',
        'quantity' => 150,
        'sale_rate' => 14,
        'sale_amount' => 2100,
    ]);

    // 550 eggs are still unsold/unused (in stock or wasted) — their cost is
    // already inside purchase_amount but they've earned nothing back yet,
    // so this period's profit legitimately comes out negative.
    EggWaste::create(['waste_date' => '2026-09-04', 'quantity' => 20]);

    $this->actingAs($user);

    // 3,600 (Tiffin) + 2,100 (external) - 10,000 (purchase cost) = -4,300.
    expect(Volt::test('egg-purchases.purchase-manager')->viewData('eggProfitTotal'))->toBe(-4300.0);
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
        'purchase_rate' => 11.5,
        'purchase_amount' => 11500,
        'sale_rate' => 11.5,
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

test('a user without egg sales permission cannot see the Sold Externally figure or the Egg Sales link', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::EggPurchasesView->value]);

    $this->actingAs($staff);

    Volt::test('egg-purchases.purchase-manager')
        ->assertDontSee('Sold Externally')
        ->assertDontSee('Egg Sales');
});

test('recording waste persists it and reduces stock on hand', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);
    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 1000,
        'purchase_rate' => 11.5,
        'purchase_amount' => 11500,
        'sale_rate' => 11.5,
    ]);

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'waste')
        ->call('startCreateWaste')
        ->set('waste_date', '2026-09-05')
        ->set('waste_quantity', '25')
        ->set('waste_remarks', 'Broken during delivery')
        ->call('saveWaste')
        ->assertHasNoErrors()
        ->assertSee('Broken during delivery');

    $this->assertDatabaseHas('egg_wastes', [
        'quantity' => 25,
        'remarks' => 'Broken during delivery',
    ]);

    $component = Volt::test('egg-purchases.purchase-manager');
    // 1000 purchased - 0 consumed - 0 sold - 25 wasted = 975.
    expect($component->viewData('eggInStock'))->toBe(975.0);
});

test('editing a waste record updates it', function () {
    $user = User::factory()->create();
    $waste = EggWaste::create(['waste_date' => '2026-09-02', 'quantity' => 20]);

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
        ->call('startEditWaste', $waste->id)
        ->set('waste_quantity', '30')
        ->call('saveWaste')
        ->assertHasNoErrors();

    expect((float) $waste->fresh()->quantity)->toBe(30.0);
});

test('deleting a waste record removes it', function () {
    $user = User::factory()->create();
    $waste = EggWaste::create(['waste_date' => '2026-09-02', 'quantity' => 20]);

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
        ->call('confirmDeleteWaste', $waste->id)
        ->call('deleteWaste');

    $this->assertDatabaseMissing('egg_wastes', ['id' => $waste->id]);
});

test('quantity is required to record waste', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('egg-purchases.purchase-manager')
        ->call('startCreateWaste')
        ->set('waste_date', '2026-09-05')
        ->set('waste_quantity', '')
        ->call('saveWaste')
        ->assertHasErrors(['waste_quantity']);
});

test('waste year and month filters narrow the listed waste records', function () {
    $user = User::factory()->create();

    EggWaste::create(['waste_date' => '2025-08-10', 'quantity' => 10]);
    EggWaste::create(['waste_date' => '2026-03-05', 'quantity' => 15]);

    $this->actingAs($user);

    $byYear = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'waste')
        ->set('wasteYearFilter', '2025');
    expect($byYear->viewData('wastes'))->toHaveCount(1);

    $byMonth = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'waste')
        ->set('wasteYearFilter', '2026')
        ->set('wasteMonthFilter', '3');
    expect($byMonth->viewData('wastes'))->toHaveCount(1);
});

test('the waste quick range filter only counts records within that window', function () {
    $user = User::factory()->create();

    EggWaste::create(['waste_date' => now()->subDays(3)->toDateString(), 'quantity' => 10]);
    EggWaste::create(['waste_date' => now()->subDays(20)->toDateString(), 'quantity' => 15]);

    $this->actingAs($user);

    $byWeek = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'waste')
        ->set('wasteRangeFilter', '7');
    expect($byWeek->viewData('wastes'))->toHaveCount(1);

    $byThirtyDays = Volt::test('egg-purchases.purchase-manager')
        ->call('switchView', 'waste')
        ->set('wasteRangeFilter', '30');
    expect($byThirtyDays->viewData('wastes'))->toHaveCount(2);
});

test('an accountant can record waste but gets a 403 trying to edit or delete it', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::EggPurchasesCreate->value]);
    $waste = EggWaste::create(['waste_date' => '2026-09-01', 'quantity' => 20]);

    $this->actingAs($accountant);

    Volt::test('egg-purchases.purchase-manager')
        ->call('startCreateWaste')
        ->set('waste_date', '2026-09-05')
        ->set('waste_quantity', '10')
        ->call('saveWaste')
        ->assertHasNoErrors();

    Volt::test('egg-purchases.purchase-manager')
        ->call('startEditWaste', $waste->id)
        ->set('waste_quantity', '999')
        ->call('saveWaste')
        ->assertForbidden();

    Volt::test('egg-purchases.purchase-manager')
        ->call('confirmDeleteWaste', $waste->id)
        ->call('deleteWaste')
        ->assertForbidden();
});
