<?php

use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/company-agreements')->assertRedirect('/login');
});

test('recording an agreement persists it against the chosen company', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create(['name' => 'Simba Fashion']);

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('title', 'Service Agreement 2026-2027')
        ->set('start_date', '2026-01-01')
        ->set('end_date', '2026-12-31')
        ->call('save')
        ->assertHasNoErrors();

    $agreement = CompanyAgreement::where('company_id', $simba->id)->firstOrFail();
    expect($agreement->title)->toBe('Service Agreement 2026-2027');
    expect($agreement->end_date->toDateString())->toBe('2026-12-31');
});

test('company, title and end date are required', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('startCreate')
        ->call('save')
        ->assertHasErrors(['company_id', 'title', 'end_date']);
});

test('end date cannot be before start date', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('title', 'Bad Dates')
        ->set('start_date', '2026-06-01')
        ->set('end_date', '2026-01-01')
        ->call('save')
        ->assertHasErrors(['end_date']);
});

test('the company filter narrows the listed agreements', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $ananta = Company::factory()->create();

    CompanyAgreement::factory()->create(['company_id' => $simba->id]);
    CompanyAgreement::factory()->create(['company_id' => $simba->id]);
    CompanyAgreement::factory()->create(['company_id' => $ananta->id]);

    $this->actingAs($user);

    $component = Volt::test('company-agreements.agreement-manager')
        ->set('companyFilter', (string) $simba->id);

    expect($component->viewData('agreements'))->toHaveCount(2);
});

test('the status filter narrows to active, expiring soon, or expired agreements', function () {
    $user = User::factory()->create();

    $active = CompanyAgreement::factory()->create(['end_date' => now()->addDays(90)->toDateString()]);
    $expiringSoon = CompanyAgreement::factory()->create(['end_date' => now()->addDays(10)->toDateString()]);
    $expired = CompanyAgreement::factory()->create(['end_date' => now()->subDays(5)->toDateString()]);

    $this->actingAs($user);

    $activeOnly = Volt::test('company-agreements.agreement-manager')->set('statusFilter', 'active');
    expect($activeOnly->viewData('agreements')->pluck('id'))->toContain($active->id);
    expect($activeOnly->viewData('agreements')->pluck('id'))->not->toContain($expiringSoon->id, $expired->id);

    $expiringOnly = Volt::test('company-agreements.agreement-manager')->set('statusFilter', 'expiring');
    expect($expiringOnly->viewData('agreements')->pluck('id'))->toContain($expiringSoon->id);
    expect($expiringOnly->viewData('agreements')->pluck('id'))->not->toContain($active->id, $expired->id);

    $expiredOnly = Volt::test('company-agreements.agreement-manager')->set('statusFilter', 'expired');
    expect($expiredOnly->viewData('agreements')->pluck('id'))->toContain($expired->id);
    expect($expiredOnly->viewData('agreements')->pluck('id'))->not->toContain($active->id, $expiringSoon->id);

    expect(Volt::test('company-agreements.agreement-manager')->viewData('expiringCount'))->toBe(1);
    expect(Volt::test('company-agreements.agreement-manager')->viewData('expiredCount'))->toBe(1);
});

test('editing an agreement updates it', function () {
    $user = User::factory()->create();
    $agreement = CompanyAgreement::factory()->create(['title' => 'Original Title']);

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('startEdit', $agreement->id)
        ->set('title', 'Renewed Title')
        ->call('save')
        ->assertHasNoErrors();

    expect($agreement->fresh()->title)->toBe('Renewed Title');
});

test('changing the deadline clears a prior alert so the resend clock restarts', function () {
    $user = User::factory()->create();
    $agreement = CompanyAgreement::factory()->create([
        'end_date' => now()->addDays(5)->toDateString(),
        'last_alerted_at' => now()->subDay(),
    ]);

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('startEdit', $agreement->id)
        ->set('end_date', now()->addYear()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect($agreement->fresh()->last_alerted_at)->toBeNull();
});

test('deleting an agreement removes it', function () {
    $user = User::factory()->create();
    $agreement = CompanyAgreement::factory()->create();

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('confirmDelete', $agreement->id)
        ->call('delete');

    $this->assertDatabaseMissing('company_agreements', ['id' => $agreement->id]);
});

test('uploading an agreement document stores it against the agreement', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $simba = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('title', 'Service Agreement')
        ->set('end_date', '2026-12-31')
        ->set('documentFile', UploadedFile::fake()->image('agreement.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $agreement = CompanyAgreement::where('company_id', $simba->id)->firstOrFail();
    expect($agreement->document_path)->not->toBeNull();
    Storage::disk('public')->assertExists($agreement->document_path);
});

test('deleting an agreement also deletes its document file from storage', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $agreement = CompanyAgreement::factory()->create(['document_path' => 'company-agreements/existing.jpg']);
    Storage::disk('public')->put('company-agreements/existing.jpg', 'fake-image-content');

    $this->actingAs($user);

    Volt::test('company-agreements.agreement-manager')
        ->call('confirmDelete', $agreement->id)
        ->call('delete');

    Storage::disk('public')->assertMissing('company-agreements/existing.jpg');
});

test('an accountant can record an agreement but gets a 403 trying to edit or delete one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::CompanyAgreementsCreate->value]);
    $simba = Company::factory()->create();
    $agreement = CompanyAgreement::factory()->create(['company_id' => $simba->id]);

    $this->actingAs($accountant);

    Volt::test('company-agreements.agreement-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('title', 'New Agreement')
        ->set('end_date', '2026-12-31')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('company-agreements.agreement-manager')
        ->call('startEdit', $agreement->id)
        ->set('title', 'Hijacked')
        ->call('save')
        ->assertForbidden();

    Volt::test('company-agreements.agreement-manager')
        ->call('confirmDelete', $agreement->id)
        ->call('delete')
        ->assertForbidden();
});
