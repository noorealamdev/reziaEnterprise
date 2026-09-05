<?php

use App\Models\Setting;
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

test('the users list paginates once there are more than one page\'s worth', function () {
    $superAdmin = User::factory()->create();

    // 10 per page — this plus the seeded super admin makes more than one page.
    User::factory()->count(10)->create();

    $this->actingAs($superAdmin);

    Volt::test('settings.user-manager')
        ->assertSeeHtml('rel="next"');
});
