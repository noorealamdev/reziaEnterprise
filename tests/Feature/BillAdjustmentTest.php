<?php

use App\Models\Company;
use App\Models\CompanyPurchase;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\User;
use Livewire\Volt\Volt;

test('recording a bill adjustment reduces the invoice balance and the purchase remaining balance', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create(['name' => 'Simba Fashion']);
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $simba->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'SIMBA-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 450000]);

    $purchase = CompanyPurchase::factory()->create([
        'company_id' => $simba->id,
        'description' => 'Garment Lot',
        'bill_number' => 'SF-2026-01',
        'amount' => 500000,
    ]);

    $this->actingAs($user);

    $component = Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment');

    expect($component->viewData('canAdjustAgainstPurchase'))->toBeTrue();

    $component
        ->set('paymentType', 'adjustment')
        ->set('companyPurchaseId', $purchase->id)
        ->set('paymentAmount', '450000')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    expect($invoice->payments)->toHaveCount(1);

    $payment = $invoice->payments->first();
    expect($payment->type)->toBe('adjustment');
    expect($payment->company_purchase_id)->toBe($purchase->id);
    expect((float) $payment->amount)->toBe(450000.0);
    expect($payment->check_number)->toBeNull();
    expect($payment->bank_name)->toBeNull();

    // 500,000 - 450,000 adjusted = 50,000 remaining, matching the client's
    // own example exactly.
    expect($purchase->fresh()->remainingBalance)->toBe(50000.0);
});

test('an adjustment amount cannot exceed the purchase bills remaining balance', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $simba->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'SIMBA-2',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 600000]);

    $purchase = CompanyPurchase::factory()->create([
        'company_id' => $simba->id,
        'amount' => 500000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->set('paymentType', 'adjustment')
        ->set('companyPurchaseId', $purchase->id)
        ->set('paymentAmount', '600000')
        ->call('recordPayment')
        ->assertHasErrors(['companyPurchaseId']);

    expect($invoice->fresh()->payments)->toHaveCount(0);
});

test('an adjustment against a purchase belonging to a different company is rejected', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $ananta = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $simba->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'SIMBA-3',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 10000]);

    $anantaPurchase = CompanyPurchase::factory()->create([
        'company_id' => $ananta->id,
        'amount' => 50000,
    ]);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->set('paymentType', 'adjustment')
        ->set('companyPurchaseId', $anantaPurchase->id)
        ->set('paymentAmount', '10000')
        ->call('recordPayment')
        ->assertHasErrors(['companyPurchaseId']);
});

test('the bill adjustment option is not offered when the company has no purchase with remaining balance', function () {
    $user = User::factory()->create();
    $ananta = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $ananta->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'AAL-1',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 10000]);

    $this->actingAs($user);

    $component = Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment');

    expect($component->viewData('canAdjustAgainstPurchase'))->toBeFalse();
    expect($component->viewData('availableAdjustmentPurchases'))->toHaveCount(0);
    $component->assertDontSee('Bill Adjustment');
});

test('a fully consumed purchase bill no longer appears as an adjustment option', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $simba->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'SIMBA-4',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 10000]);

    $purchase = CompanyPurchase::factory()->create([
        'company_id' => $simba->id,
        'amount' => 5000,
    ]);
    // Already fully adjusted against some other invoice.
    $otherInvoice = Invoice::create([
        'company_id' => $simba->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'SIMBA-4-OTHER',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'status' => 'due',
    ]);
    $otherInvoice->payments()->create([
        'amount' => 5000,
        'paid_on' => '2026-07-31',
        'type' => 'adjustment',
        'company_purchase_id' => $purchase->id,
    ]);

    $this->actingAs($user);

    $component = Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment');

    expect($component->viewData('availableAdjustmentPurchases'))->toHaveCount(0);
    expect($component->viewData('canAdjustAgainstPurchase'))->toBeFalse();
});

test('deleting an adjustment payment restores the purchase remaining balance', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $simba->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'SIMBA-5',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 20000]);

    $purchase = CompanyPurchase::factory()->create([
        'company_id' => $simba->id,
        'amount' => 20000,
    ]);
    $payment = $invoice->payments()->create([
        'amount' => 20000,
        'paid_on' => '2026-08-15',
        'type' => 'adjustment',
        'company_purchase_id' => $purchase->id,
    ]);

    expect($purchase->fresh()->remainingBalance)->toBe(0.0);

    $this->actingAs($user);

    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('confirmDeletePayment', $payment->id)
        ->call('deletePayment');

    expect($invoice->fresh()->status)->toBe('due');
    expect($purchase->fresh()->remainingBalance)->toBe(20000.0);
});

test('a partial cash payment and a partial bill adjustment on the same invoice both count toward paid', function () {
    $user = User::factory()->create();
    $simba = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');
    $invoice = Invoice::create([
        'company_id' => $simba->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'SIMBA-6',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'due',
    ]);
    JobEntry::factory()->create(['service_category_id' => $category->id, 'invoice_id' => $invoice->id, 'bill_amount' => 1000]);

    $purchase = CompanyPurchase::factory()->create([
        'company_id' => $simba->id,
        'amount' => 5000,
    ]);

    $this->actingAs($user);

    // Cash first.
    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->set('paymentAmount', '400')
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe('partial');

    // Then a bill adjustment for the remainder.
    Volt::test('invoices.invoice-detail', ['invoice' => $invoice])
        ->call('startRecordPayment')
        ->assertSet('paymentAmount', '600')
        ->set('paymentType', 'adjustment')
        ->set('companyPurchaseId', $purchase->id)
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe('paid');
    expect($invoice->fresh()->payments)->toHaveCount(2);
    expect($purchase->fresh()->remainingBalance)->toBe(4400.0);
});
