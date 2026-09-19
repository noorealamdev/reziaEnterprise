<?php

use App\Models\RolePermission;
use App\Models\SajjatTransaction;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/sajjat')->assertRedirect('/login');
});

test('a top-up raises the chosen wallet and an expense lowers only the wallet it was paid from', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.wallet-manager')
        ->call('startTopUp')
        ->set('wallet', 'bkash')
        ->set('amount', '5000')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('sajjat.wallet-manager')
        ->call('startTopUp')
        ->set('wallet', 'cash')
        ->set('amount', '2000')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('wallet', 'cash')
        ->set('amount', '350.50')
        ->set('description', 'Transport')
        ->call('save')
        ->assertHasNoErrors();

    expect(SajjatTransaction::balances())->toBe(['bkash' => 5000.0, 'cash' => 1649.5]);

    $component = Volt::test('sajjat.wallet-manager');
    expect($component->viewData('totalBalance'))->toBe(6649.5);
    $component->assertSee('Transport')->assertSee('Top-up');
});

test('spending more than was topped up shows a negative balance instead of blocking the entry', function () {
    $this->actingAs(User::factory()->create());
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'cash', 'amount' => 100]);

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('wallet', 'cash')
        ->set('amount', '250')
        ->set('description', 'Emergency')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Overspent');

    expect(SajjatTransaction::balances()['cash'])->toBe(-150.0);
});

test('an expense needs a description but a top-up defaults its note', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('amount', '50')
        ->call('save')
        ->assertHasErrors(['description']);

    Volt::test('sajjat.wallet-manager')
        ->call('startTopUp')
        ->set('amount', '50')
        ->call('save')
        ->assertHasNoErrors();

    expect(SajjatTransaction::sole()->description)->toBe('Top-up');
});

test('wallet, type and amount are validated', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('wallet', 'paypal')
        ->set('amount', '0')
        ->set('description', 'X')
        ->call('save')
        ->assertHasErrors(['wallet', 'amount']);

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('type', 'gift')
        ->set('amount', '10')
        ->set('description', 'X')
        ->call('save')
        ->assertHasErrors(['type']);

    expect(SajjatTransaction::count())->toBe(0);
});

test('the balance cards stay all-time while the period totals follow the filters', function () {
    $this->actingAs(User::factory()->create());
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'cash', 'amount' => 1000, 'transaction_date' => '2026-08-05']);
    SajjatTransaction::factory()->create(['wallet' => 'cash', 'amount' => 300, 'transaction_date' => '2026-09-05']);
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'bkash', 'amount' => 500, 'transaction_date' => '2026-09-06']);

    $september = Volt::test('sajjat.wallet-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '9');

    expect($september->viewData('topUpsInView'))->toBe(500.0);
    expect($september->viewData('spentInView'))->toBe(300.0);
    expect($september->viewData('balances'))->toBe(['bkash' => 500.0, 'cash' => 700.0]);

    $cashOnly = Volt::test('sajjat.wallet-manager')->set('walletFilter', 'cash');
    expect($cashOnly->viewData('transactions'))->toHaveCount(2);
});

test('editing and deleting an entry recalculates the balance', function () {
    $this->actingAs(User::factory()->create());
    $topUp = SajjatTransaction::factory()->topUp()->create(['wallet' => 'cash', 'amount' => 1000]);
    $expense = SajjatTransaction::factory()->create(['wallet' => 'cash', 'amount' => 400]);

    Volt::test('sajjat.wallet-manager')
        ->call('startEdit', $expense->id)
        ->set('amount', '250')
        ->call('save')
        ->assertHasNoErrors();
    expect(SajjatTransaction::balances()['cash'])->toBe(750.0);

    Volt::test('sajjat.wallet-manager')
        ->call('confirmDelete', $topUp->id)
        ->call('delete');
    expect(SajjatTransaction::balances()['cash'])->toBe(-250.0);
});

test('a user with only sajjat.view sees the ledger but cannot add, edit or delete', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::SajjatView->value]);
    $entry = SajjatTransaction::factory()->create();

    $this->actingAs($staff)->get('/sajjat')->assertOk();

    Volt::test('sajjat.wallet-manager')
        ->assertDontSee('+ Record Expense')
        ->assertDontSee('+ Top Up')
        ->call('startExpense')
        ->set('amount', '10')
        ->set('description', 'X')
        ->call('save')
        ->assertForbidden();

    Volt::test('sajjat.wallet-manager')->call('startEdit', $entry->id)->call('save')->assertForbidden();
    Volt::test('sajjat.wallet-manager')->call('confirmDelete', $entry->id)->call('delete')->assertForbidden();
});

test('an accountant without the sajjat permissions is forbidden from the page', function () {
    $this->actingAs(User::factory()->accountant()->create())
        ->get('/sajjat')
        ->assertForbidden();
});

test('the type filter narrows the ledger to top-ups or expenses', function () {
    $this->actingAs(User::factory()->create());
    SajjatTransaction::factory()->topUp()->count(2)->create();
    SajjatTransaction::factory()->count(3)->create();

    expect(Volt::test('sajjat.wallet-manager')->set('typeFilter', 'top_up')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('sajjat.wallet-manager')->set('typeFilter', 'expense')->viewData('transactions'))->toHaveCount(3);
    expect(Volt::test('sajjat.wallet-manager')->set('typeFilter', 'nonsense')->viewData('transactions'))->toHaveCount(5);
});

test('search matches the description or the remarks, case-insensitively', function () {
    $this->actingAs(User::factory()->create());
    SajjatTransaction::factory()->create(['description' => 'Loading labour tip', 'remarks' => null]);
    SajjatTransaction::factory()->create(['description' => 'Transport', 'remarks' => 'paid to the Loading crew']);
    SajjatTransaction::factory()->create(['description' => 'Tea', 'remarks' => null]);

    $found = Volt::test('sajjat.wallet-manager')->set('search', 'loading');
    expect($found->viewData('transactions')->pluck('description')->all())
        ->toEqualCanonicalizing(['Loading labour tip', 'Transport']);
});

test('quick range shows only entries within the last N days, counting today', function () {
    $this->actingAs(User::factory()->create());
    SajjatTransaction::factory()->create(['transaction_date' => now()->toDateString()]);
    SajjatTransaction::factory()->create(['transaction_date' => now()->subDays(6)->toDateString()]);
    SajjatTransaction::factory()->create(['transaction_date' => now()->subDays(7)->toDateString()]);
    SajjatTransaction::factory()->create(['transaction_date' => now()->subDays(40)->toDateString()]);

    expect(Volt::test('sajjat.wallet-manager')->set('rangeFilter', '1')->viewData('transactions'))->toHaveCount(1);
    expect(Volt::test('sajjat.wallet-manager')->set('rangeFilter', '7')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('sajjat.wallet-manager')->set('rangeFilter', '30')->viewData('transactions'))->toHaveCount(3);
});

test('a custom from-to range is inclusive and either end can be left open', function () {
    $this->actingAs(User::factory()->create());
    foreach (['2026-09-01', '2026-09-10', '2026-09-20'] as $date) {
        SajjatTransaction::factory()->create(['transaction_date' => $date]);
    }

    expect(Volt::test('sajjat.wallet-manager')->set('dateFrom', '2026-09-10')->set('dateTo', '2026-09-20')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('sajjat.wallet-manager')->set('dateFrom', '2026-09-11')->viewData('transactions'))->toHaveCount(1);
    expect(Volt::test('sajjat.wallet-manager')->set('dateTo', '2026-09-10')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('sajjat.wallet-manager')->set('dateFrom', 'garbage')->viewData('transactions'))->toHaveCount(3);
});

test('the date scopes are mutually exclusive and the totals are labelled with the active one', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.wallet-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '9')
        ->assertSee('September 2026')
        ->set('rangeFilter', '7')
        ->assertSet('yearFilter', '')
        ->assertSet('monthFilter', '')
        ->assertSee('last 7 days')
        ->set('dateFrom', '2026-09-01')
        ->assertSet('rangeFilter', '')
        ->set('dateTo', '2026-09-19')
        ->assertSee('01 Sep 2026 – 19 Sep 2026')
        ->set('yearFilter', '2026')
        ->assertSet('dateFrom', '')
        ->assertSet('dateTo', '');
});

test('filters combine, and the totals follow them while balances stay all-time', function () {
    $this->actingAs(User::factory()->create());
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'cash', 'amount' => 1000, 'description' => 'Top-up', 'transaction_date' => '2026-09-01']);
    SajjatTransaction::factory()->create(['wallet' => 'cash', 'amount' => 200, 'description' => 'Tea', 'transaction_date' => '2026-09-02']);
    SajjatTransaction::factory()->create(['wallet' => 'bkash', 'amount' => 300, 'description' => 'Tea', 'transaction_date' => '2026-09-03']);

    $view = Volt::test('sajjat.wallet-manager')
        ->set('walletFilter', 'cash')
        ->set('typeFilter', 'expense')
        ->set('search', 'tea');

    expect($view->viewData('transactions'))->toHaveCount(1);
    expect($view->viewData('spentInView'))->toBe(200.0);
    expect($view->viewData('topUpsInView'))->toBe(0.0);
    expect($view->viewData('balances'))->toBe(['bkash' => -300.0, 'cash' => 800.0]);
});

test('clear filters resets every filter at once', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.wallet-manager')
        ->set('walletFilter', 'cash')
        ->set('typeFilter', 'expense')
        ->set('search', 'tea')
        ->set('dateFrom', '2026-09-01')
        ->assertSee('Clear filters')
        ->call('clearFilters')
        ->assertSet('walletFilter', '')
        ->assertSet('typeFilter', '')
        ->assertSet('search', '')
        ->assertSet('dateFrom', '')
        ->assertDontSee('Clear filters');
});

test('an entry\'s remarks are shown on its row, and rows without remarks show none', function () {
    $this->actingAs(User::factory()->create());
    SajjatTransaction::factory()->create(['description' => 'Transport', 'remarks' => 'Went to the port twice']);
    SajjatTransaction::factory()->create(['description' => 'Tea', 'remarks' => null]);

    Volt::test('sajjat.wallet-manager')
        ->assertSee('Went to the port twice');

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('amount', '75')
        ->set('description', 'Snacks')
        ->set('remarks', 'Bought for the loading crew')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Bought for the loading crew');
});

test('creating, editing and deleting an entry each show a toast alert', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('amount', '50')
        ->set('description', 'Tea')
        ->call('save')
        ->assertDispatched('toast', message: 'Expense recorded.', type: 'success');

    Volt::test('sajjat.wallet-manager')
        ->call('startTopUp')
        ->set('amount', '500')
        ->call('save')
        ->assertDispatched('toast', message: 'Top-up recorded.', type: 'success');

    $entry = SajjatTransaction::where('description', 'Tea')->sole();

    Volt::test('sajjat.wallet-manager')
        ->call('startEdit', $entry->id)
        ->set('amount', '75')
        ->call('save')
        ->assertDispatched('toast', message: 'Entry updated.', type: 'success');

    Volt::test('sajjat.wallet-manager')
        ->call('confirmDelete', $entry->id)
        ->call('delete')
        ->assertDispatched('toast', message: 'Entry deleted.', type: 'success');
});

test('a failed validation shows no success toast', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('sajjat.wallet-manager')
        ->call('startExpense')
        ->set('amount', '50')
        ->call('save')
        ->assertHasErrors(['description'])
        ->assertNotDispatched('toast');
});
