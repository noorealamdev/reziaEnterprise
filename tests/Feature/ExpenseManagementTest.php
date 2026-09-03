<?php

use App\Models\Expense;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/expenses')->assertRedirect('/login');
});

test('expenses index lists a seeded expense', function () {
    $user = User::factory()->create();
    Expense::factory()->create(['description' => 'Transport cost', 'amount' => 500]);

    $this->actingAs($user)
        ->get('/expenses')
        ->assertOk()
        ->assertSeeVolt('expenses.expense-manager')
        ->assertSee('Transport cost')
        ->assertSee('500.00');
});

test('year and month filters narrow the listed expenses and their total', function () {
    $user = User::factory()->create();
    Expense::factory()->create(['description' => 'In scope', 'amount' => 300, 'expense_date' => '2026-08-15']);
    Expense::factory()->create(['description' => 'Out of scope', 'amount' => 700, 'expense_date' => '2025-08-15']);

    $this->actingAs($user);

    $component = Volt::test('expenses.expense-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '8');

    $component->assertSee('In scope')->assertDontSee('Out of scope');
    expect($component->viewData('filteredTotal'))->toBe(300.0);
});

test('changing the year clears an incompatible month selection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('expenses.expense-manager')
        ->set('monthFilter', '8')
        ->set('yearFilter', '2026')
        ->assertSet('monthFilter', '');
});

test('recording an expense persists it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('expenses.expense-manager')
        ->call('startCreate')
        ->set('expense_date', '2026-09-03')
        ->set('amount', '250.50')
        ->set('description', 'Tea bill')
        ->call('save')
        ->assertHasNoErrors();

    $expense = Expense::where('description', 'Tea bill')->firstOrFail();
    expect((float) $expense->amount)->toBe(250.5);
    expect($expense->expense_date->toDateString())->toBe('2026-09-03');
});

test('amount and description are required', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('expenses.expense-manager')
        ->call('startCreate')
        ->set('amount', '')
        ->set('description', '')
        ->call('save')
        ->assertHasErrors(['amount' => 'required', 'description' => 'required']);
});

test('editing an expense updates it', function () {
    $user = User::factory()->create();
    $expense = Expense::factory()->create(['description' => 'Old description', 'amount' => 100]);

    $this->actingAs($user);

    Volt::test('expenses.expense-manager')
        ->call('startEdit', $expense->id)
        ->set('description', 'New description')
        ->set('amount', '150')
        ->call('save')
        ->assertHasNoErrors();

    expect($expense->fresh()->description)->toBe('New description');
    expect((float) $expense->fresh()->amount)->toBe(150.0);
});

test('deleting an expense removes it', function () {
    $user = User::factory()->create();
    $expense = Expense::factory()->create();

    $this->actingAs($user);

    Volt::test('expenses.expense-manager')
        ->call('confirmDelete', $expense->id)
        ->call('delete');

    $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
});

test('an accountant can record an expense but gets a 403 trying to edit or delete one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::ExpensesView->value]);
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::ExpensesCreate->value]);
    $expense = Expense::factory()->create();

    $this->actingAs($accountant);

    Volt::test('expenses.expense-manager')
        ->call('startCreate')
        ->set('expense_date', now()->toDateString())
        ->set('amount', '100')
        ->set('description', 'Transport cost')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('expenses.expense-manager')
        ->call('startEdit', $expense->id)
        ->set('amount', '200')
        ->call('save')
        ->assertForbidden();

    Volt::test('expenses.expense-manager')
        ->call('confirmDelete', $expense->id)
        ->call('delete')
        ->assertForbidden();
});

test('a staff user with no grants is forbidden from recording an expense', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::ExpensesView->value]);

    $this->actingAs($staff);

    Volt::test('expenses.expense-manager')
        ->call('startCreate')
        ->set('expense_date', now()->toDateString())
        ->set('amount', '100')
        ->set('description', 'Transport cost')
        ->call('save')
        ->assertForbidden();
});
