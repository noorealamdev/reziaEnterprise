<?php

use App\Models\Newspaper;
use App\Models\NewspaperPayment;
use App\Models\RolePermission;
use App\Models\SajjatTransaction;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

function fundSajjat(float $bkash = 0, float $cash = 0): void
{
    foreach (['bkash' => $bkash, 'cash' => $cash] as $wallet => $amount) {
        if ($amount > 0) {
            SajjatTransaction::factory()->topUp()->create(['wallet' => $wallet, 'amount' => $amount]);
        }
    }
}

test('guests are redirected to login', function () {
    $this->get(route('sajjat.newspapers'))->assertRedirect('/login');
});

test('a super admin can add a newspaper with its journalist, phone and whatsapp', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.newspaper-manager')
        ->call('startCreate')
        ->set('name', 'Daily Star')
        ->set('journalist_name', 'Karim Uddin')
        ->set('phone', '01712345678')
        ->set('whatsapp', '01812345678')
        ->set('monthly_amount', '1500')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $newspaper = Newspaper::firstOrFail();
    expect($newspaper->name)->toBe('Daily Star');
    expect($newspaper->callUrl())->toBe('tel:01712345678');
    expect($newspaper->whatsappUrl())->toBe('https://wa.me/8801812345678');
});

test('newspaper name, journalist and phone are required', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.newspaper-manager')
        ->call('startCreate')
        ->call('save')
        ->assertHasErrors(['name', 'journalist_name', 'phone']);
});

test('the whatsapp button only appears when a whatsapp number is saved', function () {
    $this->actingAs(User::factory()->create());
    Newspaper::factory()->create(['name' => 'No WhatsApp Paper', 'whatsapp' => null]);

    Volt::test('sajjat.newspaper-manager')->assertDontSee('wa.me');

    Newspaper::factory()->create(['whatsapp' => '+8801912345678']);

    Volt::test('sajjat.newspaper-manager')->assertSee('https://wa.me/8801912345678', false);
});

test('recording payments shows due, partial and paid for the chosen month', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create(['monthly_amount' => 1000]);
    fundSajjat(bkash: 5000);

    $statusOf = fn () => Volt::test('sajjat.newspaper-manager')->viewData('rows')->first()->status;

    expect($statusOf())->toBe('due');

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->assertSet('pay_amount', '1000.00')
        ->assertSet('pay_wallet', 'bkash')
        ->set('pay_amount', '400')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect($statusOf())->toBe('partial');

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->assertSet('pay_amount', '600.00')
        ->call('savePayment');

    $row = Volt::test('sajjat.newspaper-manager')->viewData('rows')->first();
    expect($row->status)->toBe('paid');
    expect($row->paid)->toBe(1000.0);

    $other = Volt::test('sajjat.newspaper-manager')
        ->set('period', now()->subMonth()->format('Y-m'))
        ->viewData('rows')->first();
    expect($other->status)->toBe('due');
    expect($newspaper->payments()->first()->for_month->format('Y-m'))->toBe(now()->format('Y-m'));
});

test('a payment is deducted from the chosen wallet and shows as an expense in the sajjat ledger', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create(['name' => 'Daily Star']);
    fundSajjat(bkash: 3000, cash: 2000);

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_wallet', 'cash')
        ->set('pay_amount', '800')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect(SajjatTransaction::balances())->toBe(['bkash' => 3000.0, 'cash' => 1200.0]);

    $expense = SajjatTransaction::where('type', SajjatTransaction::TYPE_EXPENSE)->firstOrFail();
    expect($expense->description)->toContain('Daily Star');
    expect($expense->wallet)->toBe('cash');
    expect($newspaper->payments()->first()->sajjat_transaction_id)->toBe($expense->id);
});

test('a payment is blocked when the chosen wallet does not have enough balance', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create();
    fundSajjat(bkash: 500, cash: 9000);

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_amount', '501')
        ->call('savePayment')
        ->assertHasErrors(['pay_amount']);

    expect(NewspaperPayment::count())->toBe(0);
    expect(SajjatTransaction::balances()['bkash'])->toBe(500.0);

    // Exactly the available balance is fine.
    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_amount', '500')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect(SajjatTransaction::balances()['bkash'])->toBe(0.0);
});

test('with nothing topped up no newspaper can be paid', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create();

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_amount', '100')
        ->call('savePayment')
        ->assertHasErrors(['pay_amount']);

    expect(NewspaperPayment::count())->toBe(0);
});

test('deleting a payment puts the money back into the wallet', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create(['monthly_amount' => 500]);
    fundSajjat(bkash: 1000);

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->call('savePayment');

    expect(SajjatTransaction::balances()['bkash'])->toBe(500.0);
    $payment = NewspaperPayment::firstOrFail();

    Volt::test('sajjat.newspaper-manager')
        ->call('confirmPaymentDelete', $payment->id)
        ->call('deletePayment');

    $this->assertDatabaseMissing('newspaper_payments', ['id' => $payment->id]);
    expect(SajjatTransaction::balances()['bkash'])->toBe(1000.0);
    expect(Volt::test('sajjat.newspaper-manager')->viewData('rows')->first()->status)->toBe('due');
});

test('a newspaper payment expense cannot be edited or deleted from the wallet page', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create();
    fundSajjat(bkash: 1000);

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_amount', '300')
        ->call('savePayment');

    $expense = SajjatTransaction::where('type', SajjatTransaction::TYPE_EXPENSE)->firstOrFail();

    Volt::test('sajjat.wallet-manager')
        ->call('startEdit', $expense->id)
        ->assertDispatched('toast', type: 'error')
        ->call('confirmDelete', $expense->id)
        ->call('delete');

    $this->assertDatabaseHas('sajjat_transactions', ['id' => $expense->id]);
});

test('a newspaper with no fixed monthly amount counts as paid once anything is paid', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create(['monthly_amount' => null]);
    fundSajjat(bkash: 1000);

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_amount', '250')
        ->call('savePayment')
        ->assertHasNoErrors();

    $row = Volt::test('sajjat.newspaper-manager')->viewData('rows')->first();
    expect($row->status)->toBe('paid');
});

test('a payment needs a positive amount', function () {
    $this->actingAs(User::factory()->create());
    $newspaper = Newspaper::factory()->create();

    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_amount', '0')
        ->call('savePayment')
        ->assertHasErrors(['pay_amount']);
});

test('a newspaper with payments cannot be deleted but one without can', function () {
    $this->actingAs(User::factory()->create());
    $paid = Newspaper::factory()->create();
    NewspaperPayment::factory()->create(['newspaper_id' => $paid->id]);
    $unpaid = Newspaper::factory()->create();

    Volt::test('sajjat.newspaper-manager')
        ->call('confirmDelete', $paid->id)
        ->call('delete')
        ->assertDispatched('toast', type: 'error');

    Volt::test('sajjat.newspaper-manager')
        ->call('confirmDelete', $unpaid->id)
        ->call('delete');

    $this->assertDatabaseHas('newspapers', ['id' => $paid->id]);
    $this->assertDatabaseMissing('newspapers', ['id' => $unpaid->id]);
});

test('search, status filter and an invalid month in the url all behave', function () {
    $this->actingAs(User::factory()->create());
    Newspaper::factory()->create(['name' => 'Prothom Alo']);
    Newspaper::factory()->create(['name' => 'Ittefaq']);
    Newspaper::factory()->create(['name' => 'Retired Times', 'is_active' => false]);

    expect(Volt::test('sajjat.newspaper-manager')->set('search', 'Alo')->viewData('rows')->pluck('newspaper.name')->all())->toBe(['Prothom Alo']);
    expect(Volt::test('sajjat.newspaper-manager')->set('statusFilter', 'inactive')->viewData('rows')->pluck('newspaper.name')->all())->toBe(['Retired Times']);

    Volt::test('sajjat.newspaper-manager', ['period' => 'garbage'])->assertOk();
    $this->get(route('sajjat.newspapers', ['month' => 'garbage']))->assertOk();
});

test('access follows the newspaper permissions, separate from the wallet', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff)->get(route('sajjat.newspapers'))->assertForbidden();

    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::SajjatNewspapersView->value]);

    $this->actingAs($staff->fresh())->get(route('sajjat.newspapers'))->assertOk();
    $this->actingAs($staff->fresh())->get(route('sajjat.index'))->assertForbidden();

    // View only: cannot add or record payments.
    $newspaper = Newspaper::factory()->create();
    fundSajjat(bkash: 1000);
    Volt::test('sajjat.newspaper-manager')
        ->call('startPayment', $newspaper->id)
        ->set('pay_amount', '100')
        ->call('savePayment')
        ->assertForbidden();
});

test('the newspaper permissions appear in the roles grid', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    Volt::test('settings.role-permissions-manager')
        ->assertSee('View Sazzad newspapers');
});
