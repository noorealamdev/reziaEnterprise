<?php

use App\Models\CanteenEntry;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/canteen')->assertRedirect('/login');
});

test('the Canteen group appears on the Roles & Permissions screen with all three permissions, like every other menu', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    Volt::test('settings.role-permissions-manager')
        ->assertSee('Canteen')
        ->assertSee(Permission::CanteenView->label())
        ->assertSee(Permission::CanteenCreate->label())
        ->assertSee(Permission::CanteenModify->label());
});

test('a super admin can grant and revoke Canteen access to Staff from the Roles & Permissions screen', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $staff = User::factory()->staff()->create();
    $this->actingAs($superAdmin);

    expect(Gate::forUser($staff)->denies('canteen.view'))->toBeTrue();

    Volt::test('settings.role-permissions-manager')
        ->set('staffGrants.'.Permission::CanteenView->name, true)
        ->set('staffGrants.'.Permission::CanteenCreate->name, true)
        ->call('saveStaff')
        ->assertHasNoErrors();

    $staff = $staff->fresh();
    expect(Gate::forUser($staff)->allows('canteen.view'))->toBeTrue();
    expect(Gate::forUser($staff)->allows('canteen.create'))->toBeTrue();
    expect(Gate::forUser($staff)->denies('canteen.modify'))->toBeTrue();
    $this->actingAs($staff)->get('/canteen')->assertOk();

    // Switch back to the super admin — the revoke itself is also gated by
    // users.manage, so it must happen as someone who holds it.
    $this->actingAs($superAdmin);

    Volt::test('settings.role-permissions-manager')
        ->set('staffGrants.'.Permission::CanteenView->name, false)
        ->set('staffGrants.'.Permission::CanteenCreate->name, false)
        ->call('saveStaff');

    expect(Gate::forUser($staff->fresh())->denies('canteen.view'))->toBeTrue();
});

test('an entry is recorded with its net profit computed from profit minus daily cost', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('canteen.ledger-manager')
        ->call('startCreate')
        ->set('entry_date', '2026-09-01')
        ->set('total_purchases', '13280')
        ->set('total_profit', '4160')
        ->set('daily_cost', '760')
        ->call('save')
        ->assertHasNoErrors();

    $entry = CanteenEntry::sole();
    expect((float) $entry->total_purchases)->toBe(13280.0);
    expect($entry->net_profit)->toBe(3400.0);
});

test('a day can only be logged once', function () {
    $this->actingAs(User::factory()->create());
    CanteenEntry::factory()->create(['entry_date' => '2026-09-01']);

    Volt::test('canteen.ledger-manager')
        ->call('startCreate')
        ->set('entry_date', '2026-09-01')
        ->set('total_purchases', '14000')
        ->set('total_profit', '4000')
        ->set('daily_cost', '200')
        ->call('save')
        ->assertHasErrors(['entry_date']);

    expect(CanteenEntry::count())->toBe(1);
});

test('editing an entry keeps its own date from tripping the uniqueness check', function () {
    $this->actingAs(User::factory()->create());
    $entry = CanteenEntry::factory()->create(['entry_date' => '2026-09-01', 'total_profit' => 4000, 'daily_cost' => 200]);

    Volt::test('canteen.ledger-manager')
        ->call('startEdit', $entry->id)
        ->set('total_profit', '4800')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $entry->fresh()->total_profit)->toBe(4800.0);
    expect($entry->fresh()->net_profit)->toBe(4600.0);
});

test('date, purchases, profit and daily cost are all required', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('canteen.ledger-manager')
        ->call('startCreate')
        ->set('entry_date', '')
        ->call('save')
        ->assertHasErrors(['entry_date', 'total_purchases', 'total_profit', 'daily_cost']);

    expect(CanteenEntry::count())->toBe(0);
});

test('the period totals follow the filters', function () {
    $this->actingAs(User::factory()->create());
    CanteenEntry::factory()->create(['entry_date' => '2026-08-05', 'total_purchases' => 10000, 'total_profit' => 4000, 'daily_cost' => 500]);
    CanteenEntry::factory()->create(['entry_date' => '2026-09-05', 'total_purchases' => 15000, 'total_profit' => 4800, 'daily_cost' => 200]);
    CanteenEntry::factory()->create(['entry_date' => '2026-09-06', 'total_purchases' => 15400, 'total_profit' => 4700, 'daily_cost' => 760]);

    $september = Volt::test('canteen.ledger-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '9');

    expect($september->viewData('purchasesInView'))->toBe(30400.0);
    expect($september->viewData('profitInView'))->toBe(9500.0);
    expect($september->viewData('costInView'))->toBe(960.0);
    expect($september->viewData('netProfitInView'))->toBe(8540.0);
    expect($september->viewData('entries'))->toHaveCount(2);
});

test('editing and deleting an entry is reflected immediately', function () {
    $this->actingAs(User::factory()->create());
    $entry = CanteenEntry::factory()->create();

    Volt::test('canteen.ledger-manager')
        ->call('startEdit', $entry->id)
        ->set('total_purchases', '20000')
        ->call('save')
        ->assertHasNoErrors();
    expect((float) $entry->fresh()->total_purchases)->toBe(20000.0);

    Volt::test('canteen.ledger-manager')
        ->call('confirmDelete', $entry->id)
        ->call('delete');
    expect(CanteenEntry::count())->toBe(0);
});

test('a user with only canteen.view sees the ledger but cannot add, edit or delete', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::CanteenView->value]);
    $entry = CanteenEntry::factory()->create();

    $this->actingAs($staff)->get('/canteen')->assertOk();

    Volt::test('canteen.ledger-manager')
        ->assertDontSee('+ Add Entry')
        ->call('startCreate')
        ->set('entry_date', '2026-09-10')
        ->set('total_purchases', '100')
        ->set('total_profit', '50')
        ->set('daily_cost', '10')
        ->call('save')
        ->assertForbidden();

    Volt::test('canteen.ledger-manager')->call('startEdit', $entry->id)->call('save')->assertForbidden();
    Volt::test('canteen.ledger-manager')->call('confirmDelete', $entry->id)->call('delete')->assertForbidden();
});

test('an accountant without the canteen permissions is forbidden from the page', function () {
    $this->actingAs(User::factory()->accountant()->create())
        ->get('/canteen')
        ->assertForbidden();
});

test('search matches the remarks, case-insensitively', function () {
    $this->actingAs(User::factory()->create());
    CanteenEntry::factory()->create(['remarks' => 'Gas cylinder replaced']);
    CanteenEntry::factory()->create(['remarks' => 'Extra rice bought']);
    CanteenEntry::factory()->create(['remarks' => null]);

    $found = Volt::test('canteen.ledger-manager')->set('search', 'gas');
    expect($found->viewData('entries'))->toHaveCount(1);
});

test('quick range shows only entries within the last N days, counting today', function () {
    $this->actingAs(User::factory()->create());
    CanteenEntry::factory()->create(['entry_date' => now()->toDateString()]);
    CanteenEntry::factory()->create(['entry_date' => now()->subDays(6)->toDateString()]);
    CanteenEntry::factory()->create(['entry_date' => now()->subDays(7)->toDateString()]);
    CanteenEntry::factory()->create(['entry_date' => now()->subDays(40)->toDateString()]);

    expect(Volt::test('canteen.ledger-manager')->set('rangeFilter', '1')->viewData('entries'))->toHaveCount(1);
    expect(Volt::test('canteen.ledger-manager')->set('rangeFilter', '7')->viewData('entries'))->toHaveCount(2);
    expect(Volt::test('canteen.ledger-manager')->set('rangeFilter', '30')->viewData('entries'))->toHaveCount(3);
});

test('a custom from-to range is inclusive and either end can be left open', function () {
    $this->actingAs(User::factory()->create());
    foreach (['2026-09-01', '2026-09-10', '2026-09-20'] as $date) {
        CanteenEntry::factory()->create(['entry_date' => $date]);
    }

    expect(Volt::test('canteen.ledger-manager')->set('dateFrom', '2026-09-10')->set('dateTo', '2026-09-20')->viewData('entries'))->toHaveCount(2);
    expect(Volt::test('canteen.ledger-manager')->set('dateFrom', '2026-09-11')->viewData('entries'))->toHaveCount(1);
    expect(Volt::test('canteen.ledger-manager')->set('dateTo', '2026-09-10')->viewData('entries'))->toHaveCount(2);
    expect(Volt::test('canteen.ledger-manager')->set('dateFrom', 'garbage')->viewData('entries'))->toHaveCount(3);
});

test('the date scopes are mutually exclusive and the totals are labelled with the active one', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('canteen.ledger-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '9')
        ->assertSee('September 2026')
        ->set('rangeFilter', '7')
        ->assertSet('yearFilter', '')
        ->assertSet('monthFilter', '')
        ->assertSee('last 7 days')
        ->set('dateFrom', '2026-09-01')
        ->assertSet('rangeFilter', '')
        ->set('dateTo', '2026-09-19')
        ->assertSee('01 Sep 2026 – 19 Sep 2026')
        ->set('yearFilter', '2026')
        ->assertSet('dateFrom', '')
        ->assertSet('dateTo', '');
});

test('clear filters resets every filter at once', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('canteen.ledger-manager')
        ->set('search', 'gas')
        ->set('dateFrom', '2026-09-01')
        ->assertSee('Clear filters')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('dateFrom', '')
        ->assertDontSee('Clear filters');
});

test('an entry\'s remarks are shown on its row, and rows without remarks show none', function () {
    $this->actingAs(User::factory()->create());
    CanteenEntry::factory()->create(['remarks' => 'Fridge repaired']);
    CanteenEntry::factory()->create(['remarks' => null]);

    Volt::test('canteen.ledger-manager')->assertSee('Fridge repaired');
});

test('creating, editing and deleting an entry each show a toast alert', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('canteen.ledger-manager')
        ->call('startCreate')
        ->set('entry_date', '2026-09-01')
        ->set('total_purchases', '13280')
        ->set('total_profit', '4160')
        ->set('daily_cost', '760')
        ->call('save')
        ->assertDispatched('toast', message: 'Entry recorded.', type: 'success');

    $entry = CanteenEntry::sole();

    Volt::test('canteen.ledger-manager')
        ->call('startEdit', $entry->id)
        ->set('total_profit', '4500')
        ->call('save')
        ->assertDispatched('toast', message: 'Entry updated.', type: 'success');

    Volt::test('canteen.ledger-manager')
        ->call('confirmDelete', $entry->id)
        ->call('delete')
        ->assertDispatched('toast', message: 'Entry deleted.', type: 'success');
});

test('a failed validation shows no success toast', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('canteen.ledger-manager')
        ->call('startCreate')
        ->call('save')
        ->assertHasErrors()
        ->assertNotDispatched('toast');
});
