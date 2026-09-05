<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyPurchase;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SimbaFashionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reproduces Rezia's real, day-to-day arrangement with Simba Fashion,
     * as described by the client directly:
     *
     *   - Rezia buys garment lots from Simba at different times — each
     *     purchase is its own record (own date, amount, bill number), so
     *     the purchase history is visible, not just a running total.
     *   - Rezia continuously bills Simba for Tiffin/labour, same as any
     *     other client.
     *   - Instead of paying every bill in cash, Simba settles most bills
     *     by having Rezia apply money from an existing garment purchase
     *     against a bill (a "Bill Adjustment") — one purchase can cover
     *     more than one bill. Simba does still pay some bills normally,
     *     by check.
     *
     * Concretely, this seeds:
     *   - Purchase A "Garment Lot — Batch 1": 200,000 (June)
     *   - Purchase B "Garment Lot — Batch 2": 300,000 (July)
     *   - Bill 1 (June, 80,000) + Bill 2 (June, 120,000): together
     *     adjusted entirely against Purchase A — 80,000 + 120,000 =
     *     200,000, exactly using it up. Both marked Paid.
     *   - Bill 3 (July, 150,000): only 100,000 adjusted against
     *     Purchase B, leaving 50,000 still due on the bill and 200,000
     *     still unused on Purchase B.
     *   - Bill 4 (August, 90,000): paid in full by check — no
     *     adjustment involved at all.
     *   - Bill 5 (September, 60,000): paid 40,000 by check, 20,000
     *     still due.
     *
     * End state: Simba owes Rezia 70,000 (50,000 + 20,000, across the
     * two still-due bills). Rezia owes Simba 200,000 (what's left of
     * Purchase B — Purchase A is fully used up). Two separate,
     * never-netted numbers, built from a realistic mix of settlement
     * methods across several bills and several purchases.
     *
     * Deletes every existing Simba record first and rebuilds from
     * scratch with plain create() calls, so this always leaves exactly
     * this dataset regardless of what a previous run left behind.
     *
     * DatabaseSeeder runs inside Model::withoutEvents() (via
     * WithoutModelEvents), so JobEntry's `saving` hook that normally
     * computes profit_amount won't fire here — set directly instead,
     * same convention as JobEntrySeeder.
     */
    public function run(): void
    {
        $simba = Company::where('code', 'SIMBA')->first();
        $category = ServiceCategory::where('name', 'Daily Basic Labour')->first();

        if (! $simba || ! $category) {
            return;
        }

        $this->wipeExistingData($simba);

        $today = now();
        $june = $today->copy()->subMonthsNoOverflow(3)->startOfMonth();
        $july = $today->copy()->subMonthsNoOverflow(2)->startOfMonth();
        $august = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $september = $today->copy()->startOfMonth();

        // --- Purchases: garment lots Rezia bought from Simba, at
        // different times — what Rezia owes Simba. ------------------------

        $purchaseA = $this->createPurchase($simba, 'SF-2026-01', 'Garment Lot — Batch 1', 200000, $june->copy()->day(1));
        $purchaseB = $this->createPurchase($simba, 'SF-2026-02', 'Garment Lot — Batch 2', 300000, $july->copy()->day(1));

        // --- Bills: what Simba owes Rezia for Tiffin/labour ---------------

        $bill1 = $this->createInvoice($simba, $category, $june, 'SIMBA-DiBL-'.$june->format('Ym'), [
            ['day' => 5, 'quantity' => 10, 'cost_rate' => 4000, 'bill_rate' => 8000],
        ]);
        $bill2 = $this->createInvoice($simba, $category, $june, 'SIMBA-DiBL-'.$june->format('Ym').'-2', [
            ['day' => 20, 'quantity' => 10, 'cost_rate' => 6000, 'bill_rate' => 12000],
        ]);
        $bill3 = $this->createInvoice($simba, $category, $july, 'SIMBA-DiBL-'.$july->format('Ym'), [
            ['day' => 5, 'quantity' => 15, 'cost_rate' => 6000, 'bill_rate' => 10000],
        ]);
        $bill4 = $this->createInvoice($simba, $category, $august, 'SIMBA-DiBL-'.$august->format('Ym'), [
            ['day' => 5, 'quantity' => 9, 'cost_rate' => 6000, 'bill_rate' => 10000],
        ]);
        $bill5 = $this->createInvoice($simba, $category, $september, 'SIMBA-DiBL-'.$september->format('Ym'), [
            ['day' => 5, 'quantity' => 6, 'cost_rate' => 6000, 'bill_rate' => 10000],
        ]);

        // --- Settlements ----------------------------------------------------

        // Purchase A (200,000) exactly covers Bill 1 + Bill 2 — Simba
        // adjusting "200,000 for 2 bills," in the client's own words.
        $this->adjust($bill1, $purchaseA, 80000, $june->copy()->day(10));
        $this->adjust($bill2, $purchaseA, 120000, $june->copy()->day(25));

        // Purchase B (300,000) only partly covers Bill 3 — 100,000 used,
        // 200,000 left on the purchase, 50,000 left due on the bill.
        $this->adjust($bill3, $purchaseB, 100000, $july->copy()->day(15));

        // Bill 4: a plain check payment, no adjustment at all.
        $this->cashPayment($bill4, 90000, $august->copy()->day(10));

        // Bill 5: a partial check payment — 20,000 stays due.
        $this->cashPayment($bill5, 40000, $september->copy()->day(5));
    }

    /**
     * Wipes every existing Simba Fashion record before rebuilding, so
     * this seeder always leaves exactly the dataset built above.
     * Payments must go first: both invoices and company purchases
     * restrict deletion while a payment still references them.
     */
    private function wipeExistingData(Company $simba): void
    {
        $invoiceIds = Invoice::where('company_id', $simba->id)->pluck('id');
        InvoicePayment::whereIn('invoice_id', $invoiceIds)->delete();
        JobEntry::where('company_id', $simba->id)->delete();
        Invoice::where('company_id', $simba->id)->delete();
        CompanyPurchase::where('company_id', $simba->id)->delete();
    }

    private function createPurchase(Company $simba, string $billNumber, string $description, float $amount, Carbon $date): CompanyPurchase
    {
        return CompanyPurchase::create([
            'company_id' => $simba->id,
            'purchase_date' => $date->toDateString(),
            'description' => $description,
            'bill_number' => $billNumber,
            'quantity' => null,
            'rate' => null,
            'amount' => $amount,
            'remarks' => 'Simba may settle its Tiffin/labour bills against this purchase instead of paying cash.',
        ]);
    }

    /**
     * @param  array<int, array{day: int, quantity: float, cost_rate: float, bill_rate: float}>  $entries
     */
    private function createInvoice(Company $simba, ServiceCategory $category, Carbon $periodStart, string $invoiceNumber, array $entries): Invoice
    {
        $periodEnd = $periodStart->copy()->endOfMonth();

        $invoice = Invoice::create([
            'company_id' => $simba->id,
            'service_category_id' => $category->id,
            'invoice_number' => $invoiceNumber,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'status' => 'due',
        ]);

        foreach ($entries as $entry) {
            $costAmount = round($entry['quantity'] * $entry['cost_rate'], 2);
            $billAmount = round($entry['quantity'] * $entry['bill_rate'], 2);

            JobEntry::create([
                'company_id' => $simba->id,
                'service_category_id' => $category->id,
                'tiffin_department_id' => null,
                'supply_type' => 'Daily Basic Labour',
                'entry_date' => $periodStart->copy()->day($entry['day'])->toDateString(),
                'quantity' => $entry['quantity'],
                'cost_rate' => $entry['cost_rate'],
                'bill_rate' => $entry['bill_rate'],
                'cost_amount' => $costAmount,
                'bill_amount' => $billAmount,
                'profit_amount' => round($billAmount - $costAmount, 2),
                'invoice_id' => $invoice->id,
                'is_off_day' => false,
            ]);
        }

        return $invoice;
    }

    private function adjust(Invoice $invoice, CompanyPurchase $purchase, float $amount, Carbon $date): void
    {
        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'paid_on' => $date->toDateString(),
            'type' => 'adjustment',
            'company_purchase_id' => $purchase->id,
            'description' => "Adjusted against {$purchase->description} ({$purchase->bill_number}) instead of cash.",
        ]);

        $this->refreshInvoiceStatus($invoice);
    }

    private function cashPayment(Invoice $invoice, float $amount, Carbon $date): void
    {
        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'paid_on' => $date->toDateString(),
            'type' => 'cash',
        ]);

        $this->refreshInvoiceStatus($invoice);
    }

    /**
     * Mirrors invoice-detail.blade.php's refreshInvoiceStatus() exactly —
     * status/paid_at are plain stored columns with no model event, so a
     * seeder creating payments directly (bypassing the Livewire form)
     * must recompute them the same way that form does.
     */
    private function refreshInvoiceStatus(Invoice $invoice): void
    {
        $owed = (float) $invoice->jobEntries()->sum('bill_amount');
        $totalPaid = (float) $invoice->payments()->sum('amount');

        if ($totalPaid <= 0) {
            $status = 'due';
            $paidAt = null;
        } elseif ($totalPaid + 0.01 >= $owed) {
            $status = 'paid';
            $paidAt = $invoice->payments()->max('paid_on');
        } else {
            $status = 'partial';
            $paidAt = null;
        }

        $invoice->update(['status' => $status, 'paid_at' => $paidAt]);
    }
}
