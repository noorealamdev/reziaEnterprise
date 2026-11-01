<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WALLET_BUYER = 'Mr.Akter';

    /**
     * Mr.Akter hands his cash to the office instead of being billed monthly.
     * Each of his bills is kept in egg_wallet_conversion_archive, its payments
     * become handovers on the dates they were made, and his sales are released
     * from the bill so they count in the wallet. Other buyers are not touched.
     */
    public function up(): void
    {
        $buyer = DB::table('egg_buyers')->where('name', self::WALLET_BUYER)->first();

        $this->ensureSafeToConvert($buyer);

        Schema::table('egg_buyers', function (Blueprint $table) {
            $table->boolean('uses_cash_wallet')->default(false)->after('is_active');
        });

        Schema::create('egg_cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_buyer_id')->constrained()->restrictOnDelete();
            $table->date('handed_on');
            $table->decimal('amount', 12, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['egg_buyer_id', 'handed_on']);
        });

        Schema::create('egg_wallet_conversion_archive', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->primary();
            $table->string('invoice_number');
            $table->json('invoice');
            $table->json('payments');
            $table->json('sale_ids');
            $table->timestamp('archived_at')->nullable();
        });

        if ($buyer) {
            DB::transaction(fn () => $this->moveToWallet($buyer));
        }
    }

    /**
     * Not reversible: restore a database backup to undo this.
     */
    public function down(): void
    {
        //
    }

    /**
     * Runs before anything changes, so unexpected data stops the conversion
     * with the database exactly as it was.
     */
    private function ensureSafeToConvert(?object $buyer): void
    {
        if (! $buyer) {
            return;
        }

        $sold = (float) DB::table('egg_sales')->where('egg_buyer_id', $buyer->id)->sum('sale_amount');
        $paid = (float) DB::table('egg_invoice_payments')
            ->join('egg_invoices', 'egg_invoices.id', '=', 'egg_invoice_payments.egg_invoice_id')
            ->where('egg_invoices.egg_buyer_id', $buyer->id)
            ->sum('egg_invoice_payments.amount');

        if (round($paid, 2) > round($sold, 2)) {
            throw new RuntimeException("Cash wallet conversion stopped, nothing was changed. Payments on Mr.Akter's bills ({$paid}) are more than his sales ({$sold}).");
        }
    }

    private function moveToWallet(object $buyer): void
    {
        DB::table('egg_buyers')->where('id', $buyer->id)->update(['uses_cash_wallet' => true]);

        foreach (DB::table('egg_invoices')->where('egg_buyer_id', $buyer->id)->orderBy('id')->get() as $invoice) {
            $payments = DB::table('egg_invoice_payments')->where('egg_invoice_id', $invoice->id)->orderBy('id')->get();
            $saleIds = DB::table('egg_sales')->where('egg_invoice_id', $invoice->id)->orderBy('id')->pluck('id');

            DB::table('egg_wallet_conversion_archive')->insert([
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice' => json_encode($invoice),
                'payments' => json_encode($payments),
                'sale_ids' => json_encode($saleIds),
                'archived_at' => now(),
            ]);

            $this->paymentsBecomeHandovers($buyer, $invoice, $payments);

            DB::table('egg_sales')->where('egg_invoice_id', $invoice->id)->update(['egg_invoice_id' => null]);
            DB::table('egg_invoice_payments')->where('egg_invoice_id', $invoice->id)->delete();
            DB::table('egg_invoices')->where('id', $invoice->id)->delete();
        }
    }

    /**
     * @param  Collection<int, object>  $payments
     */
    private function paymentsBecomeHandovers(object $buyer, object $invoice, Collection $payments): void
    {
        foreach ($payments as $payment) {
            DB::table('egg_cash_handovers')->insert([
                'egg_buyer_id' => $buyer->id,
                'handed_on' => $payment->paid_on,
                'amount' => $payment->amount,
                'note' => collect([
                    $payment->description,
                    $payment->check_number ? "Check {$payment->check_number}".($payment->bank_name ? ", {$payment->bank_name}" : '') : null,
                    "Moved from bill {$invoice->invoice_number}.",
                ])->filter()->implode(' '),
                'created_by' => $payment->created_by,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        }
    }
};
