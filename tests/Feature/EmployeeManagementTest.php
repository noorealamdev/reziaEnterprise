<?php

use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/employees')->assertRedirect('/login');
});

test('employees index lists a seeded staff member', function () {
    $user = User::factory()->create();
    Employee::factory()->create(['name' => 'Mr. Monir']);

    $this->actingAs($user)
        ->get('/employees')
        ->assertOk()
        ->assertSeeVolt('employees.employee-list')
        ->assertSee('Mr. Monir');
});

test('an employees remarks is shown on its row in the employees list', function () {
    $user = User::factory()->create();
    Employee::factory()->create([
        'name' => 'Mr. Monir',
        'remarks' => 'Covers night shift on weekends',
    ]);

    $this->actingAs($user)
        ->get('/employees')
        ->assertOk()
        ->assertSee('Covers night shift on weekends');
});

test('employee list search filters by name', function () {
    $user = User::factory()->create();
    Employee::factory()->create(['name' => 'Mr. Monir']);
    Employee::factory()->create(['name' => 'Sagor Vai']);

    $this->actingAs($user);

    Volt::test('employees.employee-list')
        ->set('search', 'Monir')
        ->assertSee('Mr. Monir')
        ->assertDontSee('Sagor Vai');
});

test('creating a staff member with valid data persists and redirects', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('employees.employee-form')
        ->set('name', 'Mr. Karim')
        ->set('position', 'Driver')
        ->set('monthly_salary', '15000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('employees.index'));

    $this->assertDatabaseHas('employees', ['name' => 'Mr. Karim', 'position' => 'Driver', 'monthly_salary' => 15000]);
});

test('creating a staff member without a monthly salary fails validation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('employees.employee-form')
        ->set('name', 'Mr. Karim')
        ->set('monthly_salary', '')
        ->call('save')
        ->assertHasErrors(['monthly_salary' => 'required']);
});

test('editing a staff member updates its attributes', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['name' => 'Old Name', 'monthly_salary' => 10000]);

    $this->actingAs($user);

    Volt::test('employees.employee-form', ['employee' => $employee])
        ->set('name', 'New Name')
        ->set('monthly_salary', '12000')
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->name)->toBe('New Name');
    expect((float) $employee->fresh()->monthly_salary)->toBe(12000.0);
});

test('a staff member with no salary payments can be deleted', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create();

    $this->actingAs($user);

    Volt::test('employees.employee-list')
        ->call('confirmDelete', $employee->id)
        ->call('delete');

    $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
});

test('a staff member with recorded salary payments cannot be deleted', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create();
    SalaryPayment::create([
        'employee_id' => $employee->id,
        'for_month' => now()->startOfMonth()->toDateString(),
        'amount' => 5000,
        'paid_on' => now()->toDateString(),
    ]);

    $this->actingAs($user);

    $component = Volt::test('employees.employee-list')
        ->call('confirmDelete', $employee->id);

    expect($component->get('deleteBlockedMessage'))->not->toBe('');
    $this->assertDatabaseHas('employees', ['id' => $employee->id]);
});

test('an accountant can create a staff member but gets a 403 trying to edit one', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::EmployeesCreate->value]);
    $employee = Employee::factory()->create();

    $this->actingAs($accountant);

    $this->get(route('employees.create'))->assertOk();
    $this->get(route('employees.edit', $employee))->assertForbidden();

    Volt::test('employees.employee-list')
        ->call('confirmDelete', $employee->id)
        ->call('delete')
        ->assertForbidden();
});
