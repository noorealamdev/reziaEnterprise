<?php

use App\Models\PersonalContact;
use App\Models\PersonalPayment;
use App\Models\PersonalSale;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Volt\Volt;

test('accountants and staff cannot see or reach the personal ledger tab', function () {
    $accountant = User::factory()->accountant()->create();
    $this->actingAs($accountant);

    Volt::test('settings.settings-form')
        ->assertDontSee('Personal Ledger');

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreateContact')
        ->set('contact_name', 'Sneaky Contact')
        ->call('saveContact')
        ->assertForbidden();
});

test('a super admin can add a contact, record a sale and a payment, and see the correct balance', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($superAdmin);

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreateContact')
        ->set('contact_name', 'Karim Uddin')
        ->set('contact_factory_name', 'ABC Textiles')
        ->call('saveContact')
        ->assertHasNoErrors();

    $contact = PersonalContact::where('user_id', $superAdmin->id)->firstOrFail();
    expect($contact->name)->toBe('Karim Uddin');
    expect($contact->factory_name)->toBe('ABC Textiles');

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreateSale')
        ->set('sale_contact_id', $contact->id)
        ->set('sale_date', '2026-09-01')
        ->set('sale_description', '5 drums of oil')
        ->set('sale_amount', '15000')
        ->call('saveSale')
        ->assertHasNoErrors();

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreatePayment')
        ->set('payment_contact_id', $contact->id)
        ->set('payment_date', '2026-09-05')
        ->set('payment_amount', '6000')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect($contact->fresh()->balanceDue)->toBe(9000.0);

    $rows = Volt::test('settings.personal-ledger-manager')->viewData('contactsWithTotals');
    expect($rows->first()['totalSold'])->toBe(15000.0);
    expect($rows->first()['totalPaid'])->toBe(6000.0);
    expect($rows->first()['balanceDue'])->toBe(9000.0);
});

test('the transaction ledger merges sales and payments, newest first, and filters by contact', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $contactA = PersonalContact::factory()->create(['user_id' => $superAdmin->id, 'name' => 'Contact A']);
    $contactB = PersonalContact::factory()->create(['user_id' => $superAdmin->id, 'name' => 'Contact B']);

    PersonalSale::factory()->create(['personal_contact_id' => $contactA->id, 'sale_date' => '2026-09-01', 'amount' => 1000]);
    PersonalPayment::factory()->create(['personal_contact_id' => $contactA->id, 'payment_date' => '2026-09-03', 'amount' => 400]);
    PersonalSale::factory()->create(['personal_contact_id' => $contactB->id, 'sale_date' => '2026-09-02', 'amount' => 2000]);

    $this->actingAs($superAdmin);

    $all = Volt::test('settings.personal-ledger-manager')
        ->call('switchView', 'transactions')
        ->viewData('transactions');
    expect($all)->toHaveCount(3);
    expect($all->first()['type'])->toBe('payment');

    $filtered = Volt::test('settings.personal-ledger-manager')
        ->call('switchView', 'transactions')
        ->set('contactFilter', (string) $contactA->id)
        ->viewData('transactions');
    expect($filtered)->toHaveCount(2);
    expect($filtered->pluck('contact.id')->unique()->all())->toBe([$contactA->id]);
});

test('deleting a contact also deletes its sales and payments', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $contact = PersonalContact::factory()->create(['user_id' => $superAdmin->id]);
    $sale = PersonalSale::factory()->create(['personal_contact_id' => $contact->id]);
    $payment = PersonalPayment::factory()->create(['personal_contact_id' => $contact->id]);

    $this->actingAs($superAdmin);

    Volt::test('settings.personal-ledger-manager')
        ->call('confirmDeleteContact', $contact->id)
        ->call('deleteContact');

    $this->assertDatabaseMissing('personal_contacts', ['id' => $contact->id]);
    $this->assertDatabaseMissing('personal_sales', ['id' => $sale->id]);
    $this->assertDatabaseMissing('personal_payments', ['id' => $payment->id]);
});

test('one super admin cannot see, edit, or delete another super admin\'s personal ledger data', function () {
    $ownerAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $otherAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $ownerContact = PersonalContact::factory()->create(['user_id' => $ownerAdmin->id, 'name' => 'Owner Only Contact']);
    $sale = PersonalSale::factory()->create(['personal_contact_id' => $ownerContact->id]);

    $this->actingAs($otherAdmin);

    // The other admin's own ledger view never lists the owner's contact.
    $contacts = Volt::test('settings.personal-ledger-manager')->viewData('contacts');
    expect($contacts->pluck('id'))->not->toContain($ownerContact->id);

    // Directly attempting to edit/delete someone else's contact or sale
    // throws a not-found error rather than silently succeeding —
    // findOrFail() is always scoped to the acting user's own contacts.
    expect(fn () => Volt::test('settings.personal-ledger-manager')->call('startEditContact', $ownerContact->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => Volt::test('settings.personal-ledger-manager')->call('startEditSale', $sale->id))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseHas('personal_contacts', ['id' => $ownerContact->id]);
});

test('editing and deleting a sale and a payment updates the balance correctly', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $contact = PersonalContact::factory()->create(['user_id' => $superAdmin->id]);
    $sale = PersonalSale::factory()->create(['personal_contact_id' => $contact->id, 'amount' => 1000]);
    $payment = PersonalPayment::factory()->create(['personal_contact_id' => $contact->id, 'amount' => 300]);

    $this->actingAs($superAdmin);

    Volt::test('settings.personal-ledger-manager')
        ->call('startEditSale', $sale->id)
        ->set('sale_amount', '1500')
        ->call('saveSale')
        ->assertHasNoErrors();

    expect($contact->fresh()->balanceDue)->toBe(1200.0);

    Volt::test('settings.personal-ledger-manager')
        ->call('confirmDeletePayment', $payment->id)
        ->call('deletePayment');

    expect($contact->fresh()->balanceDue)->toBe(1500.0);
});

test('contact name, sale amount, and payment amount are required', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $contact = PersonalContact::factory()->create(['user_id' => $superAdmin->id]);

    $this->actingAs($superAdmin);

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreateContact')
        ->call('saveContact')
        ->assertHasErrors(['contact_name']);

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreateSale')
        ->set('sale_contact_id', $contact->id)
        ->call('saveSale')
        ->assertHasErrors(['sale_description', 'sale_amount']);

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreatePayment')
        ->set('payment_contact_id', $contact->id)
        ->call('savePayment')
        ->assertHasErrors(['payment_amount']);
});

test('a sale cannot be recorded against another super admin\'s contact', function () {
    $ownerAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $otherAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $ownerContact = PersonalContact::factory()->create(['user_id' => $ownerAdmin->id]);

    $this->actingAs($otherAdmin);

    Volt::test('settings.personal-ledger-manager')
        ->call('startCreateSale')
        ->set('sale_contact_id', $ownerContact->id)
        ->set('sale_date', '2026-09-01')
        ->set('sale_description', 'Should not be allowed')
        ->set('sale_amount', '500')
        ->call('saveSale')
        ->assertHasErrors(['sale_contact_id']);
});
