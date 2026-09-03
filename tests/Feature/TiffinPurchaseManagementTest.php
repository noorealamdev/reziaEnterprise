<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\RolePermission;
use App\Models\TiffinDepartment;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/tiffin-purchases')->assertRedirect('/login');
});

test('year and month filters narrow the listed purchases', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);

    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2025-08-10',
        'quantity' => 400,
        'cost_rate' => 10,
        'cost_amount' => 4000,
    ]);
    TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-03-05',
        'quantity' => 500,
        'cost_rate' => 12,
        'cost_amount' => 6000,
    ]);

    $this->actingAs($user);

    $byYear = Volt::test('tiffin-purchases.purchase-manager')->set('yearFilter', '2025');
    expect($byYear->viewData('purchases'))->toHaveCount(1);

    $byMonth = Volt::test('tiffin-purchases.purchase-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3');
    expect($byMonth->viewData('purchases'))->toHaveCount(1);

    $wrongMonth = Volt::test('tiffin-purchases.purchase-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '4');
    expect($wrongMonth->viewData('purchases'))->toHaveCount(0);

    // Years offered always reflect every purchase, not just the current filter.
    $component = Volt::test('tiffin-purchases.purchase-manager')->set('yearFilter', '2026');
    expect($component->viewData('availableYears')->all())->toBe([2026, 2025]);
});

test('changing the year clears an incompatible month selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3')
        ->set('yearFilter', '2025')
        ->assertSet('monthFilter', '');
});

test('findFor carries forward the most recent purchase on or before the date, not just an exact match', function () {
    $egg = TiffinItem::create(['name' => 'Egg']);

    $older = TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-08-28',
        'quantity' => 500,
        'cost_rate' => 11,
        'cost_amount' => 5500,
    ]);
    $newer = TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 500,
        'cost_rate' => 12.5,
        'cost_amount' => 6250,
    ]);

    // No purchase recorded on the 3rd itself — should carry forward the
    // most recent purchase on or before that date (Sep 1st), not fall
    // through to "no lock" just because the date doesn't match exactly.
    $found = TiffinItemPurchase::findFor('Egg', '2026-09-03');
    expect($found->id)->toBe($newer->id);

    // A date before any purchase existed finds nothing.
    expect(TiffinItemPurchase::findFor('Egg', '2026-08-01'))->toBeNull();

    // A date exactly on the older purchase finds that one, not the newer
    // (future-relative-to-it) one.
    $foundOlder = TiffinItemPurchase::findFor('Egg', '2026-08-28');
    expect($foundOlder->id)->toBe($older->id);
});

test('recording a purchase persists with a computed cost amount', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('startCreate')
        ->set('tiffin_item_id', $egg->id)
        ->set('purchase_date', '2026-09-02')
        ->set('quantity', '500')
        ->set('cost_rate', '12.5')
        ->set('supplier_name', 'Karim Traders')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiffin_item_purchases', [
        'tiffin_item_id' => $egg->id,
        'quantity' => 500,
        'cost_rate' => 12.5,
        'cost_amount' => 6250,
        'supplier_name' => 'Karim Traders',
    ]);

    $purchase = TiffinItemPurchase::where('tiffin_item_id', $egg->id)->firstOrFail();
    expect($purchase->purchase_date->toDateString())->toBe('2026-09-02');
});

test('selecting an item and date that already has a purchase loads it instead of erroring', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);
    $existing = TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-02',
        'quantity' => 400,
        'cost_rate' => 10,
        'cost_amount' => 4000,
    ]);

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('startCreate')
        ->set('tiffin_item_id', $egg->id)
        ->set('purchase_date', '2026-09-02')
        ->assertSet('editingId', $existing->id)
        ->assertSet('quantity', '400.00')
        ->assertSet('cost_rate', '10.00');

    $this->assertDatabaseCount('tiffin_item_purchases', 1);
});

test('editing a purchase updates it', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);
    $purchase = TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-02',
        'quantity' => 400,
        'cost_rate' => 10,
        'cost_amount' => 4000,
    ]);

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('startEdit', $purchase->id)
        ->set('quantity', '450')
        ->set('cost_rate', '11')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $purchase->fresh()->quantity)->toBe(450.0);
    expect((float) $purchase->fresh()->cost_rate)->toBe(11.0);
    expect((float) $purchase->fresh()->cost_amount)->toBe(4950.0);
});

test('deleting a purchase removes it', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);
    $purchase = TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-02',
        'quantity' => 400,
        'cost_rate' => 10,
        'cost_amount' => 4000,
    ]);

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('confirmDelete', $purchase->id)
        ->call('delete');

    $this->assertDatabaseMissing('tiffin_item_purchases', ['id' => $purchase->id]);
});

test('only Egg can be selected for a purchase', function () {
    $user = User::factory()->create();
    TiffinItem::create(['name' => 'Egg']);
    TiffinItem::create(['name' => 'Banana']);
    TiffinItem::create(['name' => 'Bread']);

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->assertSee('Egg')
        ->assertDontSee('Banana')
        ->assertDontSee('Bread');
});

test('starting a new purchase pre-selects Egg', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('startCreate')
        ->assertSet('tiffin_item_id', $egg->id);
});

test('quantity and cost rate are required', function () {
    $user = User::factory()->create();
    $egg = TiffinItem::create(['name' => 'Egg']);

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('startCreate')
        ->set('tiffin_item_id', $egg->id)
        ->set('purchase_date', '2026-09-02')
        ->call('save')
        ->assertHasErrors(['quantity', 'cost_rate']);
});

test('supply by item defaults to purchases view with Banana pre-selected', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->assertSet('activeView', 'purchases')
        ->assertSet('supplyItemFilter', 'Banana');
});

test('supply by item totals quantity across every company for a day and a month', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    // Same day, two different companies — the day's total is both combined.
    JobEntry::factory()->create([
        'company_id' => $companyA->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 100,
    ]);
    JobEntry::factory()->create([
        'company_id' => $companyB->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Banana',
        'quantity' => 150,
    ]);

    // A different day, same month — folds into the monthly total but not
    // the 15th's own day row.
    JobEntry::factory()->create([
        'company_id' => $companyA->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-20',
        'supply_type' => 'Banana',
        'quantity' => 50,
    ]);

    // A different item entirely — must never be folded into Banana's total.
    JobEntry::factory()->create([
        'company_id' => $companyA->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Egg',
        'quantity' => 9999,
    ]);

    $this->actingAs($user);

    $component = Volt::test('tiffin-purchases.purchase-manager')
        ->call('switchView', 'supply')
        ->set('supplyItemFilter', 'Banana')
        ->set('supplyYearFilter', '2026')
        ->set('supplyMonthFilter', '8');

    $days = $component->viewData('supplyDays');
    expect($days)->toHaveCount(2);
    expect($days->collect()->first()['quantity'])->toBe(50.0);
    expect($days->collect()->last()['quantity'])->toBe(250.0);
    expect($component->viewData('supplyMonthlyTotal'))->toBe(300.0);
});

test('supply items list reflects every distinct item actually used, including exchange items', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $tiffin = makeServiceCategory('Tiffin');
    $swing = TiffinDepartment::create(['name' => 'Swing']);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $tiffin->id,
        'tiffin_department_id' => $swing->id,
        'entry_date' => '2026-08-15',
        'supply_type' => 'Biscuit',
        'quantity' => 1000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('tiffin-purchases.purchase-manager');

    expect($component->viewData('supplyItems')->all())->toContain('Biscuit');
});

test('changing the supply year clears an incompatible month selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('tiffin-purchases.purchase-manager')
        ->set('supplyYearFilter', '2026')
        ->set('supplyMonthFilter', '3')
        ->set('supplyYearFilter', '2025')
        ->assertSet('supplyMonthFilter', '');
});

test('an accountant can record a purchase but gets a 403 trying to edit or delete one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::TiffinPurchasesCreate->value]);
    $egg = TiffinItem::create(['name' => 'Egg']);
    $purchase = TiffinItemPurchase::create([
        'tiffin_item_id' => $egg->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 400,
        'cost_rate' => 10,
        'cost_amount' => 4000,
    ]);

    $this->actingAs($accountant);

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('startCreate')
        ->set('tiffin_item_id', $egg->id)
        ->set('purchase_date', '2026-09-05')
        ->set('quantity', '100')
        ->set('cost_rate', '11')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('startEdit', $purchase->id)
        ->set('quantity', '999')
        ->call('save')
        ->assertForbidden();

    Volt::test('tiffin-purchases.purchase-manager')
        ->call('confirmDelete', $purchase->id)
        ->call('delete')
        ->assertForbidden();
});
