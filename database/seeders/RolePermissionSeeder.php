<?php

namespace Database\Seeders;

use App\Models\RolePermission;
use App\Permission;
use App\UserRole;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Accountant can create/submit everything in their day-to-day workflow,
     * but never edit or delete — every *Modify permission, plus
     * ServiceCategoriesManage and SettingsManage (structural/branding
     * config, not something they "submit"), is intentionally left out.
     * Staff starts with nothing — the Super Admin grants permissions to
     * them individually via Settings → Roles & Permissions.
     */
    public function run(): void
    {
        $accountantGrants = [
            Permission::DashboardView,
            Permission::DashboardViewProfit,
            Permission::CompaniesView,
            Permission::CompaniesCreate,
            Permission::JobEntriesView,
            Permission::JobEntriesCreate,
            Permission::EggPurchasesView,
            Permission::EggPurchasesCreate,
            Permission::DailySummaryView,
            Permission::BillStatementView,
            Permission::InvoicesView,
            Permission::InvoicesCreate,
            Permission::PaymentsCreate,
            Permission::EmployeesView,
            Permission::EmployeesCreate,
            Permission::SalaryPaymentsCreate,
            Permission::ExpensesView,
            Permission::ExpensesCreate,
        ];

        foreach ($accountantGrants as $permission) {
            RolePermission::updateOrCreate([
                'role' => UserRole::Accountant->value,
                'permission' => $permission->value,
            ]);
        }
    }
}
