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

    case TiffinPurchasesView = 'tiffin_purchases.view';
    case TiffinPurchasesCreate = 'tiffin_purchases.create';
    case TiffinPurchasesModify = 'tiffin_purchases.modify';

    case DailySummaryView = 'daily_summary.view';

    case ServiceCategoriesManage = 'service_categories.manage';

    case BillStatementView = 'bill_statement.view';

    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesModify = 'invoices.modify';

    case PaymentsCreate = 'payments.create';
    case PaymentsModify = 'payments.modify';

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
            self::TiffinPurchasesView => 'View Tiffin purchases',
            self::TiffinPurchasesCreate => 'Record Tiffin purchases',
            self::TiffinPurchasesModify => 'Edit / delete Tiffin purchases',
            self::DailySummaryView => 'View Daily Summary',
            self::ServiceCategoriesManage => 'Manage service categories & Tiffin items',
            self::BillStatementView => 'View Bill Statement',
            self::InvoicesView => 'View invoices',
            self::InvoicesCreate => 'Generate invoices',
            self::InvoicesModify => 'Delete invoices',
            self::PaymentsCreate => 'Record payments & upload signed copies',
            self::PaymentsModify => 'Delete payments / signed copies',
            self::SettingsManage => 'Manage company logo',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::DashboardView, self::DashboardViewProfit => 'Dashboard',
            self::CompaniesView, self::CompaniesCreate, self::CompaniesModify => 'Companies',
            self::JobEntriesView, self::JobEntriesCreate, self::JobEntriesModify => 'Job Entries',
            self::TiffinPurchasesView, self::TiffinPurchasesCreate, self::TiffinPurchasesModify => 'Tiffin Purchases',
            self::DailySummaryView => 'Daily Summary',
            self::ServiceCategoriesManage => 'Service Categories',
            self::BillStatementView => 'Bill Statement',
            self::InvoicesView, self::InvoicesCreate, self::InvoicesModify => 'Invoices',
            self::PaymentsCreate, self::PaymentsModify => 'Payments',
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
}
