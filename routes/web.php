<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// No permission gate here on purpose — this is the hardcoded post-login
// landing page (see login.blade.php's redirectIntended default), so it
// must stay reachable by every authenticated user regardless of role.
// Its Profit panel is instead hidden internally behind @can('dashboard.view_profit').
Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

// Settings itself has no single gate — the Company Logo tab is gated to
// settings.manage and the Users/Roles tabs to users.manage, each inside
// the component. Any authenticated user can load the shell; every tab
// checks its own permission and simply doesn't render if denied.
Route::view('settings', 'settings.index')
    ->middleware(['auth'])
    ->name('settings.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('companies', fn () => view('companies.index'))
        ->middleware('can:companies.view')
        ->name('companies.index');

    Route::get('companies/create', fn () => view('companies.create'))
        ->middleware('can:companies.create')
        ->name('companies.create');

    Route::get('companies/{company}', fn (Company $company) => view('companies.show', [
        'company' => $company,
    ]))->middleware('can:companies.view')->name('companies.show');

    Route::get('companies/{company}/edit', fn (Company $company) => view('companies.edit', [
        'company' => $company,
    ]))->middleware('can:companies.modify')->name('companies.edit');

    Route::get('tiffin-items', fn () => view('tiffin-items.index'))
        ->middleware('can:service_categories.manage')
        ->name('tiffin-items.index');

    Route::get('service-categories', fn () => view('service-categories.index'))
        ->middleware('can:service_categories.manage')
        ->name('service-categories.index');

    Route::get('job-entries', fn () => view('job-entries.index'))
        ->middleware('can:job_entries.view')
        ->name('job-entries.index');

    Route::get('job-entries/create', fn () => view('job-entries.create'))
        ->middleware('can:job_entries.create')
        ->name('job-entries.create');

    Route::get('job-entries/{jobEntry}/edit', fn (JobEntry $jobEntry) => view('job-entries.edit', [
        'jobEntry' => $jobEntry,
    ]))->middleware('can:job_entries.modify')->name('job-entries.edit');

    Route::get('job-entries/batches/{company}/{tiffinDepartment}/{date}/edit', function (Company $company, TiffinDepartment $tiffinDepartment, string $date) {
        return view('job-entries.batch-edit', [
            'company' => $company,
            'department' => $tiffinDepartment,
            'date' => $date,
        ]);
    })->where('date', '\d{4}-\d{2}-\d{2}')->middleware('can:job_entries.modify')->name('job-entries.batch-edit');

    Route::get('tiffin-purchases', fn () => view('tiffin-purchases.index'))
        ->middleware('can:tiffin_purchases.view')
        ->name('tiffin-purchases.index');

    // Bill Statement replaced the standalone Invoices list — this name is
    // kept only so any old link/bookmark to /invoices still lands somewhere
    // sensible instead of a broken page.
    Route::get('invoices', fn () => redirect()->route('bill-statement.index'))
        ->middleware('can:bill_statement.view')
        ->name('invoices.index');

    Route::get('invoices/create', fn () => view('invoices.create'))
        ->middleware('can:invoices.create')
        ->name('invoices.create');

    Route::get('invoices/{invoice}', fn (Invoice $invoice) => view('invoices.show', [
        'invoice' => $invoice,
    ]))->middleware('can:invoices.view')->name('invoices.show');

    Route::get('daily-summary', fn () => view('daily-summary.index'))
        ->middleware('can:daily_summary.view')
        ->name('daily-summary.index');

    Route::get('daily-summary/{company}/{date}', function (Company $company, string $date) {
        return view('daily-summary.show', [
            'company' => $company,
            'date' => $date,
        ]);
    })->where('date', '\d{4}-\d{2}-\d{2}')->middleware('can:daily_summary.view')->name('daily-summary.show');

    Route::get('bill-statement', fn () => view('bill-statement.index'))
        ->middleware('can:bill_statement.view')
        ->name('bill-statement.index');
});

require __DIR__.'/auth.php';
