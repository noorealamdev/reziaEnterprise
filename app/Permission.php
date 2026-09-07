<?php

namespace App;

enum Permission: string
{
    case DashboardView = 'dashboard.view';
    case DashboardViewProfit = 'dashboard.view_profit';

    case CompaniesView = 'companies.view';
    case CompaniesCreate = 'companies.create';
    case CompaniesModify = 'companies.modify';

    case JobEntriesView = 'job_entries.view';
    case JobEntriesCreate = 'job_entries.create';
    case JobEntriesModify = 'job_entries.modify';

    case EggPurchasesView = 'egg_purchases.view';
    case EggPurchasesCreate = 'egg_purchases.create';
    case EggPurchasesModify = 'egg_purchases.modify';

    case EggSalesView = 'egg_sales.view';
    case EggSalesCreate = 'egg_sales.create';
    case EggSalesModify = 'egg_sales.modify';

    case CompanyPurchasesView = 'company_purchases.view';
    case CompanyPurchasesCreate = 'company_purchases.create';
    case CompanyPurchasesModify = 'company_purchases.modify';

    case CompanyAgreementsView = 'company_agreements.view';
    case CompanyAgreementsCreate = 'company_agreements.create';
    case CompanyAgreementsModify = 'company_agreements.modify';

    case DailySummaryView = 'daily_summary.view';

    case ServiceCategoriesManage = 'service_categories.manage';

    case BillStatementView = 'bill_statement.view';

    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesModify = 'invoices.modify';

    case PaymentsCreate = 'payments.create';
    case PaymentsModify = 'payments.modify';

    case EmployeesView = 'employees.view';
    case EmployeesCreate = 'employees.create';
    case EmployeesModify = 'employees.modify';

    case SalaryPaymentsCreate = 'salary_payments.create';
    case SalaryPaymentsModify = 'salary_payments.modify';

    case ExpensesView = 'expenses.view';
    case ExpensesCreate = 'expenses.create';
    case ExpensesModify = 'expenses.modify';

    case SettingsManage = 'settings.manage';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'View dashboard summary (totals, charts, ready-to-invoice, today\'s activity)',
            self::DashboardViewProfit => 'View profit figures',
            self::CompaniesView => 'View companies',
            self::CompaniesCreate => 'Add companies',
            self::CompaniesModify => 'Edit / delete companies',
            self::JobEntriesView => 'View job entries',
            self::JobEntriesCreate => 'Add job entries',
            self::JobEntriesModify => 'Edit / delete job entries',
            self::EggPurchasesView => 'View Egg purchases & stock',
            self::EggPurchasesCreate => 'Record Egg purchases',
            self::EggPurchasesModify => 'Edit / delete Egg purchases',
            self::EggSalesView => 'View Egg stock & sales to outside buyers',
            self::EggSalesCreate => 'Record Egg sales to outside buyers',
            self::EggSalesModify => 'Edit / delete Egg sales',
            self::CompanyPurchasesView => 'View goods purchased from a company',
            self::CompanyPurchasesCreate => 'Record goods purchased from a company',
            self::CompanyPurchasesModify => 'Edit / delete company purchases',
            self::CompanyAgreementsView => 'View company agreements & deadlines',
            self::CompanyAgreementsCreate => 'Add company agreements',
            self::CompanyAgreementsModify => 'Edit / delete company agreements',
            self::DailySummaryView => 'View Daily Summary',
            self::ServiceCategoriesManage => 'Manage service categories & Tiffin items',
            self::BillStatementView => 'View Bill Statement',
            self::InvoicesView => 'View invoices',
            self::InvoicesCreate => 'Generate invoices',
            self::InvoicesModify => 'Delete invoices',
            self::PaymentsCreate => 'Record payments & upload signed copies',
            self::PaymentsModify => 'Delete payments / signed copies',
            self::EmployeesView => 'View staff',
            self::EmployeesCreate => 'Add staff',
            self::EmployeesModify => 'Edit / delete staff',
            self::SalaryPaymentsCreate => 'Record salary payments',
            self::SalaryPaymentsModify => 'Delete salary payments',
            self::ExpensesView => 'View expenses',
            self::ExpensesCreate => 'Record expenses',
            self::ExpensesModify => 'Edit / delete expenses',
            self::SettingsManage => 'Manage company logo',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::DashboardView, self::DashboardViewProfit => 'Dashboard',
            self::CompaniesView, self::CompaniesCreate, self::CompaniesModify => 'Companies',
            self::JobEntriesView, self::JobEntriesCreate, self::JobEntriesModify => 'Job Entries',
            self::EggPurchasesView, self::EggPurchasesCreate, self::EggPurchasesModify => 'Egg Purchases',
            self::EggSalesView, self::EggSalesCreate, self::EggSalesModify => 'Egg Sales',
            self::CompanyPurchasesView, self::CompanyPurchasesCreate, self::CompanyPurchasesModify => 'Company Purchases',
            self::CompanyAgreementsView, self::CompanyAgreementsCreate, self::CompanyAgreementsModify => 'Company Agreements',
            self::DailySummaryView => 'Daily Summary',
            self::ServiceCategoriesManage => 'Service Categories',
            self::BillStatementView => 'Bill Statement',
            self::InvoicesView, self::InvoicesCreate, self::InvoicesModify => 'Invoices',
            self::PaymentsCreate, self::PaymentsModify => 'Payments',
            self::EmployeesView, self::EmployeesCreate, self::EmployeesModify,
            self::SalaryPaymentsCreate, self::SalaryPaymentsModify => 'Staff Salaries',
            self::ExpensesView, self::ExpensesCreate, self::ExpensesModify => 'Expenses',
            self::SettingsManage => 'Settings',
        };
    }

    /**
     * Permissions grouped in a stable, feature-area order for rendering a
     * checkbox grid — Blade's grouping via a plain array preserves this
     * order, unlike grouping by a Collection keyed on group() alone.
     *
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $permission) {
            $grouped[$permission->group()][] = $permission;
        }

        return $grouped;
    }

    /**
     * Accountant may create/submit and view, but must never edit, delete,
     * or manage structural/branding settings — every permission ending in
     * .modify or .manage is out of reach for that role, full stop. Every
     * permission value follows the {domain}.{view|create|modify|manage}
     * convention, so this is a suffix check rather than a hand-maintained
     * list that a new permission could slip past.
     */
    public function isAccountantEligible(): bool
    {
        return ! str_ends_with($this->value, '.modify') && ! str_ends_with($this->value, '.manage');
    }
}
