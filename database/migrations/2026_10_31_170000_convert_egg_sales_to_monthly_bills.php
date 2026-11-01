<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Egg sales stop carrying their own payment status and are billed monthly
     * per buyer instead. Everything removed from egg_sales is kept first in
     * egg_sales_archive, including the confirmed duplicate sale 35. Sales
     * already recorded as Cash become a Paid bill so they are never charged
     * again; the rest stay unbilled, ready for their first bill.
     */
    public function up(): void
    {
        $sales = $this->salesWithNames();

        $this->ensureSafeToConvert($sales);

        Schema::create('egg_sales_archive', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_id')->primary();
            $table->date('sale_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('sale_rate', 10, 2);
            $table->decimal('sale_amount', 12, 2);
            $table->string('buyer_name')->nullable();
            $table->string('payment_status');
            $table->string('in_charge_name')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('original_created_at')->nullable();
            $table->timestamp('original_updated_at')->nullable();
            $table->boolean('removed_as_duplicate')->default(false);
        });

        Schema::table('egg_buyers', function (Blueprint $table) {
            $table->string('address')->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('remarks');
        });

        Schema::create('egg_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_buyer_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('due');
            $table->date('paid_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('signed_copy_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'period_start']);
        });

        Schema::create('egg_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('type')->default('cash');
            $table->string('check_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('check_image_path')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->foreignId('egg_invoice_id')->nullable()->after('egg_buyer_id')->constrained()->restrictOnDelete();
        });

        DB::transaction(function () use ($sales) {
            $this->archive($sales);
            DB::table('egg_sales')->whereIn('id', $sales->filter(fn (object $sale) => $this->isConfirmedDuplicate($sale, $sales))->pluck('id'))->delete();
            $this->billCashSales($sales->where('payment_status', 'cash'));
        });

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('egg_in_charge_id');
        });

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->dropColumn('payment_status');
            $table->dropForeign(['egg_buyer_id']);
        });

        Schema::table('egg_sales', function (Blueprint $table) {
            $table->unsignedBigInteger('egg_buyer_id')->nullable(false)->change();
            $table->foreign('egg_buyer_id')->references('id')->on('egg_buyers')->restrictOnDelete();
        });

        Schema::dropIfExists('egg_buyer_payments');
        Schema::dropIfExists('egg_in_charge_transactions');
        Schema::dropIfExists('egg_in_charges');
    }

    /**
     * Not reversible: restore a database backup to undo this.
     */
    public function down(): void
    {
        //
    }

    /**
     * @return Collection<int, object>
     */
    private function salesWithNames(): Collection
    {
        return DB::table('egg_sales')
            ->leftJoin('egg_buyers', 'egg_buyers.id', '=', 'egg_sales.egg_buyer_id')
            ->leftJoin('egg_in_charges', 'egg_in_charges.id', '=', 'egg_sales.egg_in_charge_id')
            ->select('egg_sales.*', 'egg_buyers.name as buyer_name', 'egg_in_charges.name as in_charge_name')
            ->orderBy('egg_sales.id')
            ->get();
    }

    /**
     * Runs before anything changes, so unexpected data stops the conversion
     * with the database exactly as it was.
     *
     * @param  Collection<int, object>  $sales
     */
    private function ensureSafeToConvert(Collection $sales): void
    {
        if (DB::table('egg_buyer_payments')->exists() || DB::table('egg_in_charge_transactions')->exists()) {
            throw new RuntimeException('Egg bill conversion stopped, nothing was changed. Buyer payments or in-charge transactions exist and would need their own conversion.');
        }

        $withoutBuyer = $sales
            ->filter(fn (object $sale) => $sale->egg_buyer_id === null && ! $this->isConfirmedDuplicate($sale, $sales))
            ->pluck('id');

        if ($withoutBuyer->isNotEmpty()) {
            throw new RuntimeException('Egg bill conversion stopped, nothing was changed. These sales have no buyer: '.$withoutBuyer->implode(', '));
        }

        $unknownStatus = $sales->whereNotIn('payment_status', ['cash', 'due'])->pluck('id');

        if ($unknownStatus->isNotEmpty()) {
            throw new RuntimeException('Egg bill conversion stopped, nothing was changed. These sales have an unexpected payment status: '.$unknownStatus->implode(', '));
        }
    }

    /**
     * Sale 35 was confirmed by the business as a duplicate of sale 45. It is
     * only ever removed if both rows still look exactly as they did then.
     *
     * @param  Collection<int, object>  $sales
     */
    private function isConfirmedDuplicate(object $sale, Collection $sales): bool
    {
        $original = $sales->firstWhere('id', 45);

        return (int) $sale->id === 35
            && $sale->egg_buyer_id === null
            && $original !== null
            && $original->buyer_name === 'Mr.Shafin'
            && substr((string) $sale->sale_date, 0, 10) === '2026-10-04'
            && (string) $original->sale_date === (string) $sale->sale_date
            && (float) $sale->quantity === 260.0
            && (float) $original->quantity === 260.0
            && (float) $sale->sale_amount === 3172.0
            && (float) $original->sale_amount === 3172.0;
    }

    /**
     * @param  Collection<int, object>  $sales
     */
    private function archive(Collection $sales): void
    {
        foreach ($sales as $sale) {
            DB::table('egg_sales_archive')->insert([
                'sale_id' => $sale->id,
                'sale_date' => $sale->sale_date,
                'quantity' => $sale->quantity,
                'sale_rate' => $sale->sale_rate,
                'sale_amount' => $sale->sale_amount,
                'buyer_name' => $sale->buyer_name,
                'payment_status' => $sale->payment_status,
                'in_charge_name' => $sale->in_charge_name,
                'remarks' => $sale->remarks,
                'created_by' => $sale->created_by,
                'original_created_at' => $sale->created_at,
                'original_updated_at' => $sale->updated_at,
                'removed_as_duplicate' => $this->isConfirmedDuplicate($sale, $sales),
            ]);
        }
    }

    /**
     * @param  Collection<int, object>  $cashSales
     */
    private function billCashSales(Collection $cashSales): void
    {
        $groups = $cashSales->groupBy(fn (object $sale) => $sale->egg_buyer_id.'|'.substr((string) $sale->sale_date, 0, 7));

        foreach ($groups as $key => $group) {
            [$buyerId, $month] = explode('|', $key);
            $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();
            $lastSaleDate = $group->max('sale_date');

            $invoiceId = DB::table('egg_invoices')->insertGetId([
                'egg_buyer_id' => (int) $buyerId,
                'invoice_number' => sprintf('RE/EGG/B%d/%s', $buyerId, $start->format('mY')),
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'status' => 'paid',
                'paid_at' => $lastSaleDate,
                'remarks' => 'Created automatically from sales already recorded as Cash.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('egg_invoice_payments')->insert([
                'egg_invoice_id' => $invoiceId,
                'amount' => round((float) $group->sum('sale_amount'), 2),
                'paid_on' => $lastSaleDate,
                'type' => 'cash',
                'description' => 'Paid in cash when these sales were recorded.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('egg_sales')->whereIn('id', $group->pluck('id'))->update(['egg_invoice_id' => $invoiceId]);
        }
    }
};
