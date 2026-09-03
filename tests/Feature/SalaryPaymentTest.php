<?php

use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('recording a payment for the current month updates the employee detail status', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['monthly_salary' => 10000]);

    $this->actingAs($user);

    $fresh = Volt::test('employees.employee-detail', ['employee' => $employee]);
    expect($fresh->viewData('statusLabel'))->toBe('Due');

    Volt::test('employees.employee-detail', ['employee' => $employee])
        ->call('startRecordPayment')
        ->set('paymentAmount', '4000')
        ->set('paymentForMonth', now()->format('Y-m'))
        ->set('paymentDate', now()->toDateString())
        ->call('recordPayment')
        ->assertHasNoErrors();

    $afterPartial = Volt::test('employees.employee-detail', ['employee' => $employee]);
    expect($afterPartial->viewData('statusLabel'))->toBe('Partially Paid');
    expect($afterPartial->viewData('balanceThisMonth'))->toBe(6000.0);

    Volt::test('employees.employee-detail', ['employee' => $employee])
        ->call('startRecordPayment')
        ->set('paymentAmount', '6000')
        ->set('paymentForMonth', now()->format('Y-m'))
        ->set('paymentDate', now()->toDateString())
        ->call('recordPayment')
        ->assertHasNoErrors();

    $afterFull = Volt::test('employees.employee-detail', ['employee' => $employee]);
    expect($afterFull->viewData('statusLabel'))->toBe('Paid');
    expect((float) $afterFull->viewData('balanceThisMonth'))->toBe(0.0);

    $this->assertDatabaseHas('salary_payments', ['employee_id' => $employee->id, 'amount' => 4000]);
    $this->assertDatabaseHas('salary_payments', ['employee_id' => $employee->id, 'amount' => 6000]);
});

test('a payment recorded for a different month does not affect this month\'s status', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['monthly_salary' => 10000]);

    $this->actingAs($user);

    Volt::test('employees.employee-detail', ['employee' => $employee])
        ->call('startRecordPayment')
        ->set('paymentAmount', '10000')
        ->set('paymentForMonth', now()->subMonth()->format('Y-m'))
        ->set('paymentDate', now()->subMonth()->toDateString())
        ->call('recordPayment')
        ->assertHasNoErrors();

    $component = Volt::test('employees.employee-detail', ['employee' => $employee]);
    expect($component->viewData('statusLabel'))->toBe('Due');
    expect($component->viewData('paidThisMonth'))->toBe(0.0);
});

test('the staff salaries statement reflects each employee\'s status for the selected month', function () {
    $user = User::factory()->create();
    $due = Employee::factory()->create(['name' => 'Due Employee', 'monthly_salary' => 10000]);
    $paid = Employee::factory()->create(['name' => 'Paid Employee', 'monthly_salary' => 8000]);

    $paid->salaryPayments()->create([
        'for_month' => now()->startOfMonth()->toDateString(),
        'amount' => 8000,
        'paid_on' => now()->toDateString(),
    ]);

    $this->actingAs($user);

    $component = Volt::test('staff-salaries.staff-salaries');

    $rows = $component->viewData('rows')->keyBy(fn ($row) => $row->employee->id);

    expect($rows[$due->id]->status)->toBe('due');
    expect($rows[$paid->id]->status)->toBe('paid');
    expect($component->viewData('totalOutstanding'))->toBe(10000.0);
});

test('removing a payment recalculates the status back down', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['monthly_salary' => 10000]);
    $payment = $employee->salaryPayments()->create([
        'for_month' => now()->startOfMonth()->toDateString(),
        'amount' => 10000,
        'paid_on' => now()->toDateString(),
    ]);

    $this->actingAs($user);

    $before = Volt::test('employees.employee-detail', ['employee' => $employee]);
    expect($before->viewData('statusLabel'))->toBe('Paid');

    $before->call('confirmDeletePayment', $payment->id)->call('deletePayment');

    $after = Volt::test('employees.employee-detail', ['employee' => $employee]);
    expect($after->viewData('statusLabel'))->toBe('Due');

    $this->assertDatabaseMissing('salary_payments', ['id' => $payment->id]);
});

test('an accountant can record a payment but gets forbidden deleting one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::EmployeesView->value]);
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::SalaryPaymentsCreate->value]);

    $employee = Employee::factory()->create(['monthly_salary' => 10000]);

    $this->actingAs($accountant);

    Volt::test('employees.employee-detail', ['employee' => $employee])
        ->call('startRecordPayment')
        ->set('paymentAmount', '10000')
        ->set('paymentForMonth', now()->format('Y-m'))
        ->set('paymentDate', now()->toDateString())
        ->call('recordPayment')
        ->assertHasNoErrors();

    $payment = $employee->fresh()->salaryPayments->first();
    expect($payment)->not->toBeNull();

    Volt::test('employees.employee-detail', ['employee' => $employee])
        ->call('confirmDeletePayment', $payment->id)
        ->call('deletePayment')
        ->assertForbidden();
});

test('visiting the employee page with a month query param opens the payment modal pre-filled', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create();
    $targetMonth = now()->addMonth()->format('Y-m');

    $this->actingAs($user);
    $this->get(route('employees.show', $employee).'?month='.$targetMonth)
        ->assertOk()
        ->assertSee($targetMonth);
});

test('an employee\'s payment history paginates without losing any recorded payment', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['monthly_salary' => 10000]);

    foreach (range(1, 12) as $i) {
        $employee->salaryPayments()->create([
            'for_month' => now()->subMonths($i)->startOfMonth()->toDateString(),
            'amount' => 1000,
            'paid_on' => now()->subMonths($i)->toDateString(),
        ]);
    }

    $this->actingAs($user);

    $component = Volt::test('employees.employee-detail', ['employee' => $employee]);

    // Only one page's worth renders in the list...
    expect($component->viewData('payments'))->toHaveCount(10);
    // ...but nothing was dropped from the database itself.
    expect($employee->salaryPayments()->count())->toBe(12);
});

test('the staff salaries statement paginates without dropping any employee from the totals', function () {
    $user = User::factory()->create();

    // More than one screen page's worth (15 per page).
    foreach (range(1, 18) as $i) {
        Employee::factory()->create(['monthly_salary' => 1000]);
    }

    $this->actingAs($user);

    $component = Volt::test('staff-salaries.staff-salaries');

    // Only one page's worth of rows renders...
    expect($component->viewData('rows'))->toHaveCount(15);
    // ...but the totals still account for all 18 employees.
    expect($component->viewData('totalExpected'))->toBe(18000.0);
});
