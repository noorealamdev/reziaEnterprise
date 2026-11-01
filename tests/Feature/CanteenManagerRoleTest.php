<?php

use App\Models\CanteenEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Volt;

test('the role starts with exactly the three Canteen permissions', function () {
    $granted = RolePermission::where('role', UserRole::CanteenManager->value)->pluck('permission')->sort()->values()->all();

    expect($granted)->toBe(['canteen.create', 'canteen.modify', 'canteen.view']);
});

test('after login they go straight to the Canteen instead of the Dashboard', function () {
    $this->actingAs(User::factory()->canteenManager()->create())
        ->get('/dashboard')
        ->assertRedirect(route('canteen.index'));
});

test('the sidebar offers the Canteen and nothing else', function () {
    $response = $this->actingAs(User::factory()->canteenManager()->create())->get('/canteen')->assertOk();

    $response->assertSeeText('Canteen');

    foreach (['Dashboard', 'Companies', 'Job Entries', 'Egg Sales', 'Egg Purchases', 'Company Purchases', 'Bill Statement', 'Staff Salaries', 'Expenses', 'Sajjat', 'Personal Ledger', 'Settings'] as $menu) {
        $response->assertDontSeeText($menu);
    }
});

test('every other page is forbidden, even by typing its address', function (string $path) {
    $this->actingAs(User::factory()->canteenManager()->create())->get($path)->assertForbidden();
})->with([
    '/companies', '/companies/create', '/service-categories', '/tiffin-items', '/loading-unloading-items',
    '/job-entries', '/job-entries/create', '/egg-purchases', '/egg-sales', '/company-purchases', '/company-agreements',
    '/licenses', '/invoices', '/invoices/create', '/invoices/create-manual', '/daily-summary', '/service-summary',
    '/bill-statement', '/employees', '/employees/create', '/staff-salaries', '/expenses', '/sajjat', '/sajjat/newspapers',
    '/personal-ledger',
]);

test('pages for a specific record are forbidden too', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create();
    $this->actingAs(User::factory()->canteenManager()->create());

    $this->get("/companies/{$company->id}")->assertForbidden();
    $this->get("/employees/{$employee->id}")->assertForbidden();
});

test('settings shows them no tabs, and profile stays available for their password', function () {
    $this->actingAs(User::factory()->canteenManager()->create());

    $this->get('/profile')->assertOk();
    $this->get('/settings')->assertOk()->assertDontSeeText('Roles & Permissions')->assertDontSeeText('Add User');
    expect(Gate::denies('users.manage'))->toBeTrue();
    expect(Gate::denies('settings.manage'))->toBeTrue();
});

test('they can add, edit and delete canteen entries', function () {
    $this->actingAs(User::factory()->canteenManager()->create());

    Volt::test('canteen.ledger-manager')
        ->call('startCreate')
        ->set('entry_date', '2026-10-01')
        ->set('items.porota.quantity', '150')
        ->set('daily_cost', '100')
        ->call('save')
        ->assertHasNoErrors();
    $entry = CanteenEntry::sole();

    Volt::test('canteen.ledger-manager')
        ->call('startEdit', $entry->id)
        ->set('items.porota.quantity', '200')
        ->call('save')
        ->assertHasNoErrors();
    expect((float) $entry->fresh()->total_profit)->toBe(400.0);

    Volt::test('canteen.ledger-manager')->call('confirmDelete', $entry->id)->call('delete');
    expect(CanteenEntry::count())->toBe(0);
});

test('a super admin can create a user with this role and change what the role can do', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    Volt::test('settings.user-manager')
        ->assertSee('Canteen Manager')
        ->call('startCreate')
        ->set('name', 'Canteen Person')
        ->set('email', 'canteen@example.com')
        ->set('password', 'a-strong-password-1')
        ->set('role', UserRole::CanteenManager->value)
        ->call('save')
        ->assertHasNoErrors();
    $manager = User::where('email', 'canteen@example.com')->sole();
    expect($manager->role)->toBe(UserRole::CanteenManager);

    Volt::test('settings.role-permissions-manager')
        ->assertSet('canteenManagerGrants.CanteenModify', true)
        ->assertSet('canteenManagerGrants.BillStatementView', false)
        ->set('canteenManagerGrants.CanteenModify', false)
        ->call('saveCanteenManager');

    expect(Gate::forUser($manager->fresh())->denies('canteen.modify'))->toBeTrue();
    expect(Gate::forUser($manager->fresh())->allows('canteen.create'))->toBeTrue();
});

test('saving this role leaves the other roles untouched', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::ExpensesView->value]);

    Volt::test('settings.role-permissions-manager')
        ->set('canteenManagerGrants.CanteenView', false)
        ->call('saveCanteenManager');

    expect(RolePermission::where('role', UserRole::Staff->value)->pluck('permission')->all())->toBe(['expenses.view']);
});

test('granting the Dashboard to the role gives them back the Dashboard', function () {
    RolePermission::create(['role' => UserRole::CanteenManager->value, 'permission' => Permission::DashboardView->value]);

    $this->actingAs(User::factory()->canteenManager()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSeeText('Dashboard');
});

test('taking away Canteen access leaves them on an empty Dashboard, not a redirect loop', function () {
    RolePermission::where('role', UserRole::CanteenManager->value)->where('permission', 'canteen.view')->delete();

    $this->actingAs(User::factory()->canteenManager()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Ready to Invoice');
});

test('other roles still land on the Dashboard', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get('/dashboard')->assertOk();
})->with(['accountant', 'staff']);
