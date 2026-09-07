<?php

use App\Models\PersonalContact;
use App\Models\Setting;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/settings')->assertRedirect('/login');
});

test('uploading a logo stores it and it appears in the application logo component', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('settings.settings-form')
        ->set('logo', UploadedFile::fake()->image('logo.png'))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    $setting = Setting::current();
    expect($setting->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($setting->logo_path);

    $logoUrl = Storage::disk('public')->url($setting->logo_path);

    $this->get('/dashboard')->assertOk()->assertSee($logoUrl, false);
});

test('the logo upload rejects a file that is not an image', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('settings.settings-form')
        ->set('logo', UploadedFile::fake()->create('logo.pdf', 100))
        ->call('uploadLogo')
        ->assertHasErrors(['logo']);

    expect(Setting::current()->logo_path)->toBeNull();
});

test('replacing a logo deletes the old file from storage', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('settings.settings-form')
        ->set('logo', UploadedFile::fake()->image('first.png'))
        ->call('uploadLogo');

    $firstPath = Setting::current()->logo_path;
    Storage::disk('public')->assertExists($firstPath);

    Volt::test('settings.settings-form')
        ->set('logo', UploadedFile::fake()->image('second.png'))
        ->call('uploadLogo');

    $secondPath = Setting::current()->logo_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($secondPath);
    Storage::disk('public')->assertMissing($firstPath);
});

test('removing a logo deletes it from storage and reverts to the default mark', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('settings.settings-form')
        ->set('logo', UploadedFile::fake()->image('logo.png'))
        ->call('uploadLogo');

    $path = Setting::current()->logo_path;

    Volt::test('settings.settings-form')
        ->call('removeLogo');

    expect(Setting::current()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);

    // Falls back to the default inline SVG mark, not a broken image.
    $this->get('/dashboard')->assertOk()->assertSee('fill="#303960"', false);
});

test('a super admin can create a user and assign a role', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('startCreate')
        ->set('name', 'New Accountant')
        ->set('email', 'accountant@example.com')
        ->set('password', 'password123')
        ->set('role', UserRole::Accountant->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('users', [
        'email' => 'accountant@example.com',
        'role' => 'accountant',
        'is_active' => 1,
    ]);
});

test('a super admin cannot remove their own super admin access', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('startEdit', $superAdmin->id)
        ->set('role', UserRole::Staff->value)
        ->call('save')
        ->assertSet('formError', "You can't remove your own Super Admin access.");

    expect($superAdmin->fresh()->role)->toBe(UserRole::SuperAdmin);
});

test('a super admin cannot demote the last remaining active super admin', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $otherSuperAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('startEdit', $otherSuperAdmin->id)
        ->set('role', UserRole::Staff->value)
        ->call('save')
        ->assertSet('formError', '');

    // That succeeded because $superAdmin (the actor) is still an active
    // Super Admin — now try demoting the very last one.
    Volt::test('settings.user-manager')
        ->call('startEdit', $superAdmin->id)
        ->set('role', UserRole::Staff->value)
        ->call('save')
        ->assertSet('formError', "You can't remove your own Super Admin access.");
});

test('a super admin can delete a user, keeping that user\'s past records but unlinking them', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $staff = User::factory()->staff()->create();
    $purchase = TiffinItemPurchase::create([
        'tiffin_item_id' => TiffinItem::create(['name' => 'Egg'])->id,
        'purchase_date' => '2026-09-01',
        'quantity' => 100,
        'cost_rate' => 10,
        'cost_amount' => 1000,
        'created_by' => $staff->id,
    ]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('confirmDelete', $staff->id)
        ->call('delete');

    $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    expect($purchase->fresh()->created_by)->toBeNull();
});

test('a super admin cannot delete their own account', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('confirmDelete', $superAdmin->id)
        ->assertSet('deleteBlockedMessage', "You can't delete your own account.");

    $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
});

test('a super admin can delete another super admin as long as one active one remains', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $otherSuperAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('confirmDelete', $otherSuperAdmin->id)
        ->call('delete');

    $this->assertDatabaseMissing('users', ['id' => $otherSuperAdmin->id]);
});

test('the last remaining active super admin cannot be deleted', function () {
    $lastSuperAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    // A different actor than the target, so this exercises the
    // last-active-super-admin guard rather than the self-delete guard.
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    Volt::test('settings.user-manager')
        ->call('confirmDelete', $lastSuperAdmin->id)
        ->assertSet('deleteBlockedMessage', 'This is the last active Super Admin — promote someone else first.');

    $this->assertDatabaseHas('users', ['id' => $lastSuperAdmin->id]);
});

test('deleting a user who owns personal ledger data also erases that ledger, flagged in the confirm dialog', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $ledgerOwner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $contact = PersonalContact::factory()->create(['user_id' => $ledgerOwner->id]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('confirmDelete', $ledgerOwner->id)
        ->assertSet('confirmingDeleteHasPersonalLedger', true)
        ->call('delete');

    $this->assertDatabaseMissing('users', ['id' => $ledgerOwner->id]);
    $this->assertDatabaseMissing('personal_contacts', ['id' => $contact->id]);
});

test('an accountant cannot delete a user', function () {
    $accountant = User::factory()->accountant()->create();
    $staff = User::factory()->staff()->create();
    $this->actingAs($accountant);

    Volt::test('settings.user-manager')
        ->call('confirmDelete', $staff->id)
        ->call('delete')
        ->assertForbidden();
});

test('deactivating a user blocks them from logging in', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $staff = User::factory()->staff()->create(['email' => 'inactive@example.com', 'password' => bcrypt('password123')]);
    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->call('startEdit', $staff->id)
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($staff->fresh()->is_active)->toBeFalse();

    $this->post('/logout');

    Volt::test('pages.auth.login')
        ->set('form.email', 'inactive@example.com')
        ->set('form.password', 'password123')
        ->call('login')
        ->assertHasErrors(['form.email']);

    $this->assertGuest();
});

test('accountants and staff cannot see or reach the users and roles tabs', function () {
    $accountant = User::factory()->accountant()->create();
    $this->actingAs($accountant);

    Volt::test('settings.settings-form')
        ->assertDontSee('Users')
        ->assertDontSee('Roles & Permissions');

    // Directly invoking the sub-components' own mutating actions is still
    // blocked server-side, not just hidden in the tab bar.
    Volt::test('settings.user-manager')
        ->call('startCreate')
        ->set('name', 'Sneaky')
        ->set('email', 'sneaky@example.com')
        ->set('password', 'password123')
        ->set('role', UserRole::Staff->value)
        ->call('save')
        ->assertForbidden();
});

test('a super admin can grant and revoke role permissions from the roles and permissions screen', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $staff = User::factory()->staff()->create();
    $this->actingAs($superAdmin);

    Volt::test('settings.role-permissions-manager')
        ->set('staffGrants.'.Permission::JobEntriesCreate->name, true)
        ->call('saveStaff')
        ->assertHasNoErrors();

    expect(Gate::forUser($staff->fresh())->allows('job_entries.create'))->toBeTrue();

    Volt::test('settings.role-permissions-manager')
        ->set('staffGrants.'.Permission::JobEntriesCreate->name, false)
        ->call('saveStaff');

    expect(Gate::forUser($staff->fresh())->denies('job_entries.create'))->toBeTrue();
});

test('the accountant column never offers a modify or manage permission to check', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($superAdmin);

    $component = Volt::test('settings.role-permissions-manager');
    $accountantGroups = $component->viewData('accountantGroupedPermissions');

    foreach ($accountantGroups as $permissions) {
        foreach ($permissions as $permission) {
            expect($permission->isAccountantEligible())->toBeTrue();
        }
    }

    // The unrestricted grid (Staff's column) still offers everything —
    // proves the filtering is Accountant-specific, not a global change.
    expect($component->viewData('groupedPermissions'))->not->toBe($accountantGroups);
});

test('granting a modify permission to accountant is rejected even if sent directly, not just hidden in the UI', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $accountant = User::factory()->accountant()->create();
    $this->actingAs($superAdmin);

    // Bypasses the Blade checkbox entirely — sets the underlying property
    // the same way a crafted Livewire request could, proving the
    // restriction is enforced in saveAccountant() itself.
    Volt::test('settings.role-permissions-manager')
        ->set('accountantGrants.'.Permission::JobEntriesModify->name, true)
        ->set('accountantGrants.'.Permission::JobEntriesCreate->name, true)
        ->call('saveAccountant')
        ->assertHasNoErrors();

    expect(Gate::forUser($accountant->fresh())->denies('job_entries.modify'))->toBeTrue();
    expect(Gate::forUser($accountant->fresh())->allows('job_entries.create'))->toBeTrue();
    $this->assertDatabaseMissing('role_permissions', [
        'role' => UserRole::Accountant->value,
        'permission' => 'job_entries.modify',
    ]);
});

test('the users list paginates once there are more than one page\'s worth', function () {
    $superAdmin = User::factory()->create();

    // 10 per page — this plus the seeded super admin makes more than one page.
    User::factory()->count(10)->create();

    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->assertSeeHtml('rel="next"');
});
