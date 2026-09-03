<?php

use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Support\Facades\Gate;

test('super admin passes every permission gate unconditionally', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    foreach (Permission::cases() as $permission) {
        expect(Gate::forUser($superAdmin)->allows($permission->value))->toBeTrue();
    }

    expect(Gate::forUser($superAdmin)->allows('users.manage'))->toBeTrue();
});

test('a fresh staff user with no grants fails every permission gate', function () {
    $staff = User::factory()->staff()->create();

    foreach (Permission::cases() as $permission) {
        expect(Gate::forUser($staff)->allows($permission->value))->toBeFalse();
    }

    expect(Gate::forUser($staff)->allows('users.manage'))->toBeFalse();
});

test('accountants seeded defaults can create but not modify', function () {
    $accountant = User::factory()->accountant()->create();
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::JobEntriesCreate->value]);
    RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => Permission::PaymentsCreate->value]);

    expect(Gate::forUser($accountant)->allows('job_entries.create'))->toBeTrue();
    expect(Gate::forUser($accountant)->denies('job_entries.modify'))->toBeTrue();
    expect(Gate::forUser($accountant)->allows('payments.create'))->toBeTrue();
    expect(Gate::forUser($accountant)->denies('payments.modify'))->toBeTrue();
    expect(Gate::forUser($accountant)->denies('invoices.modify'))->toBeTrue();
});

test('an accountant can never manage users regardless of granted permissions', function () {
    $accountant = User::factory()->accountant()->create();

    // Even if every configurable permission were somehow granted, user
    // management stays a hard Super-Admin-only check.
    foreach (Permission::cases() as $permission) {
        RolePermission::create(['role' => UserRole::Accountant->value, 'permission' => $permission->value]);
    }

    expect(Gate::forUser($accountant)->denies('users.manage'))->toBeTrue();
});

test('toggling a role permission via a RolePermission row immediately changes what a staff user can do', function () {
    $staff = User::factory()->staff()->create();

    expect(Gate::forUser($staff)->denies('job_entries.create'))->toBeTrue();

    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::JobEntriesCreate->value]);
    $staff = $staff->fresh();

    expect(Gate::forUser($staff)->allows('job_entries.create'))->toBeTrue();
});
