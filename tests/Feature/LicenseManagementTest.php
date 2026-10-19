<?php

use App\Models\License;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/licenses')->assertRedirect('/login');
});

test('recording a license persists it', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('startCreate')
        ->set('title', 'Trade License 2026-2027')
        ->set('license_number', 'TL-123456')
        ->set('issue_date', '2026-01-01')
        ->set('expiry_date', '2026-12-31')
        ->call('save')
        ->assertHasNoErrors();

    $license = License::where('title', 'Trade License 2026-2027')->firstOrFail();
    expect($license->license_number)->toBe('TL-123456');
    expect($license->expiry_date->toDateString())->toBe('2026-12-31');
});

test('title and expiry date are required', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('startCreate')
        ->call('save')
        ->assertHasErrors(['title', 'expiry_date']);
});

test('expiry date cannot be before issue date', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('startCreate')
        ->set('title', 'Bad Dates')
        ->set('issue_date', '2026-06-01')
        ->set('expiry_date', '2026-01-01')
        ->call('save')
        ->assertHasErrors(['expiry_date']);
});

test('the status filter narrows to active, expiring soon, or expired licenses', function () {
    $user = User::factory()->create();

    $active = License::factory()->create(['expiry_date' => now()->addDays(90)->toDateString()]);
    $expiringSoon = License::factory()->create(['expiry_date' => now()->addDays(10)->toDateString()]);
    $expired = License::factory()->create(['expiry_date' => now()->subDays(5)->toDateString()]);

    $this->actingAs($user);

    $activeOnly = Volt::test('licenses.license-manager')->set('statusFilter', 'active');
    expect($activeOnly->viewData('licenses')->pluck('id'))->toContain($active->id);
    expect($activeOnly->viewData('licenses')->pluck('id'))->not->toContain($expiringSoon->id, $expired->id);

    $expiringOnly = Volt::test('licenses.license-manager')->set('statusFilter', 'expiring');
    expect($expiringOnly->viewData('licenses')->pluck('id'))->toContain($expiringSoon->id);
    expect($expiringOnly->viewData('licenses')->pluck('id'))->not->toContain($active->id, $expired->id);

    $expiredOnly = Volt::test('licenses.license-manager')->set('statusFilter', 'expired');
    expect($expiredOnly->viewData('licenses')->pluck('id'))->toContain($expired->id);
    expect($expiredOnly->viewData('licenses')->pluck('id'))->not->toContain($active->id, $expiringSoon->id);

    expect(Volt::test('licenses.license-manager')->viewData('expiringCount'))->toBe(1);
    expect(Volt::test('licenses.license-manager')->viewData('expiredCount'))->toBe(1);
});

test('editing a license updates it', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['title' => 'Original Title']);

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('startEdit', $license->id)
        ->set('title', 'Renewed Title')
        ->call('save')
        ->assertHasNoErrors();

    expect($license->fresh()->title)->toBe('Renewed Title');
});

test('changing the deadline clears a prior alert so the resend clock restarts', function () {
    $user = User::factory()->create();
    $license = License::factory()->create([
        'expiry_date' => now()->addDays(5)->toDateString(),
        'last_alerted_at' => now()->subDay(),
    ]);

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('startEdit', $license->id)
        ->set('expiry_date', now()->addYear()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect($license->fresh()->last_alerted_at)->toBeNull();
});

test('deleting a license removes it', function () {
    $user = User::factory()->create();
    $license = License::factory()->create();

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('confirmDelete', $license->id)
        ->call('delete');

    $this->assertDatabaseMissing('licenses', ['id' => $license->id]);
});

test('uploading a license document stores it against the license', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('startCreate')
        ->set('title', 'Trade License')
        ->set('expiry_date', '2026-12-31')
        ->set('documentFile', UploadedFile::fake()->image('license.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $license = License::where('title', 'Trade License')->firstOrFail();
    expect($license->document_path)->not->toBeNull();
    Storage::disk('public')->assertExists($license->document_path);
});

test('deleting a license also deletes its document file from storage', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $license = License::factory()->create(['document_path' => 'licenses/existing.jpg']);
    Storage::disk('public')->put('licenses/existing.jpg', 'fake-image-content');

    $this->actingAs($user);

    Volt::test('licenses.license-manager')
        ->call('confirmDelete', $license->id)
        ->call('delete');

    Storage::disk('public')->assertMissing('licenses/existing.jpg');
});

test('an accountant can record a license but gets a 403 trying to edit or delete one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::LicensesCreate->value]);
    $license = License::factory()->create();

    $this->actingAs($accountant);

    Volt::test('licenses.license-manager')
        ->call('startCreate')
        ->set('title', 'New License')
        ->set('expiry_date', '2026-12-31')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('licenses.license-manager')
        ->call('startEdit', $license->id)
        ->set('title', 'Hijacked')
        ->call('save')
        ->assertForbidden();

    Volt::test('licenses.license-manager')
        ->call('confirmDelete', $license->id)
        ->call('delete')
        ->assertForbidden();
});
