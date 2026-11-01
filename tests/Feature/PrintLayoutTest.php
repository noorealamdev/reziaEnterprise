<?php

use App\Models\EggBuyer;
use App\Models\EggInvoice;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\User;

test('the app layout lets a page run past the screen height when printed', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('h-screen overflow-hidden bg-slate-50 dark:bg-slate-950 print:block print:h-auto print:overflow-visible', false)
        ->assertSee('flex-col overflow-y-auto print:block print:overflow-visible', false);
});

test('a factory bill prints across pages with its totals and signature kept together', function () {
    $entry = JobEntry::factory()->create(['bill_amount' => 700]);
    $invoice = Invoice::create([
        'company_id' => $entry->company_id,
        'service_category_id' => $entry->service_category_id,
        'invoice_number' => 'PRINT-FACTORY',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'status' => 'due',
    ]);
    $entry->update(['invoice_id' => $invoice->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertSee('print:!mt-0 print:overflow-visible', false)
        ->assertSee('overflow-x-auto print:overflow-visible', false)
        ->assertSee('justify-between text-sm print:break-before-avoid print:break-inside-avoid', false);
});

test('an egg bill prints across pages with its totals and signature kept together', function () {
    $bill = EggInvoice::create([
        'egg_buyer_id' => EggBuyer::factory()->create()->id,
        'invoice_number' => 'PRINT-EGG',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'status' => 'due',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('egg-sales.bills.show', $bill))
        ->assertOk()
        ->assertSee('print:!mt-0 print:overflow-visible', false)
        ->assertSee('overflow-x-auto print:overflow-visible', false)
        ->assertSee('justify-between text-sm print:break-before-avoid print:break-inside-avoid', false);
});

test('printed tables keep rows whole and show totals once, at the end', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)->toContain('@media print')
        ->toContain('break-inside: avoid')
        ->toContain('display: table-row-group');
});
