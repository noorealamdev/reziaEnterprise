<?php

use App\Models\Company;
use App\Models\CompanyPurchase;
use App\Models\CompanyPurchasePayment;
use App\Models\Invoice;
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

test('a purchase remarks is shown on its row in the purchases list', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startCreate')
        ->set('company_id', $simba->id)
        ->set('purchase_date', '2026-09-02')
        ->set('description', 'Fabric Rolls')
        ->set('amount', '25000')
        ->set('remarks', 'Paid half in advance, rest on delivery')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Paid half in advance, rest on delivery');
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

test('recording a cash payment against a purchase reduces its remaining balance', function () {
    $user = User::factory()->create();
    $purchase = CompanyPurchase::factory()->create(['amount' => 5000]);

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startRecordPurchasePayment', $purchase->id)
        ->assertSet('payAmount', '5000')
        ->set('payAmount', '1000')
        ->set('payDate', '2026-09-06')
        ->set('payRemarks', 'Leftover after adjusting bills')
        ->call('recordPurchasePayment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('company_purchase_payments', [
        'company_purchase_id' => $purchase->id,
        'amount' => 1000,
        'remarks' => 'Leftover after adjusting bills',
    ]);
    expect($purchase->fresh()->remainingBalance)->toBe(4000.0);
});

test('uploading a money receipt with a cash payment stores it', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $purchase = CompanyPurchase::factory()->create(['amount' => 5000]);

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startRecordPurchasePayment', $purchase->id)
        ->set('payAmount', '1000')
        ->set('payDate', '2026-09-06')
        ->set('payReceiptFile', UploadedFile::fake()->image('receipt.jpg'))
        ->call('recordPurchasePayment')
        ->assertHasNoErrors();

    $payment = CompanyPurchasePayment::where('company_purchase_id', $purchase->id)->firstOrFail();
    expect($payment->receipt_path)->not->toBeNull();
    Storage::disk('public')->assertExists($payment->receipt_path);
});

test('deleting a cash payment also deletes its receipt file from storage', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $purchase = CompanyPurchase::factory()->create(['amount' => 5000]);
    $payment = CompanyPurchasePayment::create([
        'company_purchase_id' => $purchase->id,
        'amount' => 1000,
        'paid_on' => '2026-09-06',
        'receipt_path' => 'company-purchase-payment-receipts/existing.jpg',
    ]);
    Storage::disk('public')->put('company-purchase-payment-receipts/existing.jpg', 'fake-image-content');

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('confirmDeletePurchasePayment', $payment->id)
        ->call('deletePurchasePayment');

    Storage::disk('public')->assertMissing('company-purchase-payment-receipts/existing.jpg');
});

test('a cash payment cannot exceed what remains on the purchase, even after an adjustment already drew it down', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = Company::factory()->create();
    $category = makeServiceCategory('Diesel Oil Supply');
    $purchase = CompanyPurchase::factory()->create(['company_id' => $company->id, 'amount' => 5000]);
    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'PAY-CAP-1',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-01',
        'status' => 'due',
        'manual_amount' => 3000,
    ]);

    // 3,000 already adjusted away, leaving 2,000 remaining.
    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->set('paymentAmount', '3000')
        ->set('paymentDate', '2026-09-02')
        ->set('paymentType', 'adjustment')
        ->set('companyPurchaseId', $purchase->id)
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($purchase->fresh()->remainingBalance)->toBe(2000.0);

    Volt::test('company-purchases.purchase-manager')
        ->call('startRecordPurchasePayment', $purchase->id)
        ->set('payAmount', '2500')
        ->set('payDate', '2026-09-06')
        ->call('recordPurchasePayment')
        ->assertHasErrors(['payAmount']);

    expect($purchase->fresh()->remainingBalance)->toBe(2000.0);
});

test('deleting a cash payment restores the purchase\'s remaining balance', function () {
    $user = User::factory()->create();
    $purchase = CompanyPurchase::factory()->create(['amount' => 5000]);

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startRecordPurchasePayment', $purchase->id)
        ->set('payAmount', '1000')
        ->set('payDate', '2026-09-06')
        ->call('recordPurchasePayment')
        ->assertHasNoErrors();

    $payment = $purchase->fresh()->payments->sole();
    expect($purchase->fresh()->remainingBalance)->toBe(4000.0);

    Volt::test('company-purchases.purchase-manager')
        ->call('confirmDeletePurchasePayment', $payment->id)
        ->call('deletePurchasePayment');

    $this->assertDatabaseMissing('company_purchase_payments', ['id' => $payment->id]);
    expect($purchase->fresh()->remainingBalance)->toBe(5000.0);
});

test('a purchase with a cash payment recorded against it cannot be deleted', function () {
    $user = User::factory()->create();
    $purchase = CompanyPurchase::factory()->create(['amount' => 5000]);

    $this->actingAs($user);

    Volt::test('company-purchases.purchase-manager')
        ->call('startRecordPurchasePayment', $purchase->id)
        ->set('payAmount', '1000')
        ->set('payDate', '2026-09-06')
        ->call('recordPurchasePayment')
        ->assertHasNoErrors();

    Volt::test('company-purchases.purchase-manager')
        ->call('confirmDelete', $purchase->id)
        ->assertSet('deleteBlockedMessage', 'This purchase has Bill Adjustments or payments recorded against it and can\'t be deleted. Remove those first if it genuinely needs to be removed.');

    $this->assertDatabaseHas('company_purchases', ['id' => $purchase->id]);
});

test('an accountant can be granted purchase-payment access but still gets a 403 removing one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::CompanyPurchasesCreate->value]);
    $purchase = CompanyPurchase::factory()->create(['amount' => 5000]);

    $this->actingAs($accountant);

    Volt::test('company-purchases.purchase-manager')
        ->call('startRecordPurchasePayment', $purchase->id)
        ->set('payAmount', '1000')
        ->set('payDate', '2026-09-06')
        ->call('recordPurchasePayment')
        ->assertForbidden();
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
