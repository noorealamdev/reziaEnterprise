<?php

use App\Models\Company;
use App\Models\CompanyPurchase;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/company-purchases')->assertRedirect('/login');
});

test('recording a purchase persists it against the chosen company', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create(['name' => 'Simba Fashion']);

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('purchase_date', '2026-09-02')
        ->set('description', 'Fabric Rolls')
        ->set('quantity', '50')
        ->set('rate', '500')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('company_purchases', [
        'company_id' => $simba->id,
        'description' => 'Fabric Rolls',
        'quantity' => 50,
        'rate' => 500,
        'amount' => 25000,
    ]);
});

test('a bill number round-trips through the create and edit forms', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('purchase_date', '2026-09-02')
        ->set('description', 'Garment Lot')
        ->set('bill_number', 'SF-2026-01')
        ->set('amount', '500000')
        ->call('save')
        ->assertHasNoErrors();

    $purchase = CompanyPurchase::where('company_id', $simba->id)->firstOrFail();
    expect($purchase->bill_number)->toBe('SF-2026-01');

    Volt::test('company-purchases.purchase-manager')
        ->call('startEdit', $purchase->id)
        ->assertSet('bill_number', 'SF-2026-01')
        ->set('bill_number', 'SF-2026-01-REV')
        ->call('save')
        ->assertHasNoErrors();

    expect($purchase->fresh()->bill_number)->toBe('SF-2026-01-REV');
});

test('quantity and rate auto-fill the amount, but amount can also be entered directly without them', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();

    $this->actingAs($user);

    // Quantity x Rate auto-fills Amount.
    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('quantity', '20')
        ->set('rate', '150')
        ->assertSet('amount', '3000');

    // A lump-sum purchase with no quantity/rate breakdown is still valid.
    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('purchase_date', '2026-09-02')
        ->set('description', 'Assorted Goods Lot')
        ->set('amount', '18000')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('company_purchases', [
        'description' => 'Assorted Goods Lot',
        'quantity' => null,
        'rate' => null,
        'amount' => 18000,
    ]);
});

test('company, description and amount are required', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('purchase_date', '2026-09-02')
        ->call('save')
        ->assertHasErrors(['company_id', 'description', 'amount']);
});

test('the company filter narrows the listed purchases and its own total', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $ananta = Company::factory()->create();

    CompanyPurchase::factory()->create(['company_id' => $simba->id, 'amount' => 10000]);
    CompanyPurchase::factory()->create(['company_id' => $simba->id, 'amount' => 5000]);
    CompanyPurchase::factory()->create(['company_id' => $ananta->id, 'amount' => 99999]);

    $this->actingAs($user);

    $component = Volt::test('company-purchases.purchase-manager')
        ->set('companyFilter', (string) $simba->id);

    expect($component->viewData('purchases'))->toHaveCount(2);
    expect($component->viewData('totalPurchased'))->toBe(15000.0);
});

test('year and month filters narrow the listed purchases', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();

    CompanyPurchase::factory()->create(['company_id' => $simba->id, 'purchase_date' => '2025-08-10']);
    CompanyPurchase::factory()->create(['company_id' => $simba->id, 'purchase_date' => '2026-03-05']);

    $this->actingAs($user);

    $byYear = Volt::test('company-purchases.purchase-manager')->set('yearFilter', '2025');
    expect($byYear->viewData('purchases'))->toHaveCount(1);

    $byMonth = Volt::test('company-purchases.purchase-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3');
    expect($byMonth->viewData('purchases'))->toHaveCount(1);
});

test('changing the year clears an incompatible month selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '3')
        ->set('yearFilter', '2025')
        ->assertSet('monthFilter', '');
});

test('editing a purchase updates it', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $purchase = CompanyPurchase::factory()->create([
        'company_id' => $simba->id,
        'description' => 'Fabric Rolls',
        'quantity' => 50,
        'rate' => 500,
        'amount' => 25000,
    ]);

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startEdit', $purchase->id)
        ->set('description', 'Fabric Rolls (Batch 2)')
        ->set('quantity', '60')
        ->set('rate', '500')
        ->call('save')
        ->assertHasNoErrors();

    expect($purchase->fresh()->description)->toBe('Fabric Rolls (Batch 2)');
    expect((float) $purchase->fresh()->amount)->toBe(30000.0);
});

test('deleting a purchase removes it', function () {
    $user = User::factory()->create();
    $purchase = CompanyPurchase::factory()->create();

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('confirmDelete', $purchase->id)
        ->call('delete');

    $this->assertDatabaseMissing('company_purchases', ['id' => $purchase->id]);
});

test('uploading a purchase memo stores it against the purchase', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $simba = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('purchase_date', '2026-09-02')
        ->set('description', 'Fabric Rolls')
        ->set('amount', '25000')
        ->set('memoFile', UploadedFile::fake()->image('memo.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $purchase = CompanyPurchase::where('company_id', $simba->id)->firstOrFail();
    expect($purchase->memo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($purchase->memo_path);
});

test('deleting a purchase also deletes its memo file from storage', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $purchase = CompanyPurchase::factory()->create(['memo_path' => 'company-purchase-memos/existing.jpg']);
    Storage::disk('public')->put('company-purchase-memos/existing.jpg', 'fake-image-content');

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('confirmDelete', $purchase->id)
        ->call('delete');

    Storage::disk('public')->assertMissing('company-purchase-memos/existing.jpg');
});

test('an accountant can record a purchase but gets a 403 trying to edit or delete one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::CompanyPurchasesCreate->value]);
    $simba = Company::factory()->create();
    $purchase = CompanyPurchase::factory()->create(['company_id' => $simba->id]);

    $this->actingAs($accountant);

    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('purchase_date', '2026-09-05')
        ->set('description', 'Fabric Rolls')
        ->set('amount', '1000')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('company-purchases.purchase-manager')
        ->call('startEdit', $purchase->id)
        ->set('amount', '999')
        ->call('save')
        ->assertForbidden();

    Volt::test('company-purchases.purchase-manager')
        ->call('confirmDelete', $purchase->id)
        ->call('delete')
        ->assertForbidden();
});
