<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sale details the egg_sale_transactions ledger never kept, recovered from
     * the live database dump taken on 2026-10-22, before the ledger replaced
     * egg_sales. Keyed by date|quantity|amount|created_at, which is unique
     * across these rows and identifies the matching ledger row.
     *
     * @var array<string, array{0: int, 1: string, 2: string, 3: bool, 4: int|null}>
     */
    private const RECOVERED_SALES = [
        '2026-09-01|3000.00|35400.00|2026-09-09 10:44:11' => [1, '2026-09-13 18:40:36', 'due', true, 1],
        '2026-09-03|3000.00|35400.00|2026-09-09 10:44:49' => [2, '2026-09-13 18:40:36', 'due', true, 1],
        '2026-09-08|3000.00|35400.00|2026-09-22 21:46:43' => [7, '2026-09-22 21:46:43', 'due', true, 1],
        '2026-09-10|3000.00|36000.00|2026-09-22 21:47:24' => [8, '2026-09-22 21:47:24', 'due', true, 1],
        '2026-09-13|3000.00|37500.00|2026-09-22 21:47:55' => [9, '2026-09-22 21:47:55', 'due', true, 1],
        '2026-09-17|1800.00|22500.00|2026-09-22 21:48:32' => [10, '2026-09-22 21:48:32', 'due', true, 1],
        '2026-09-19|3000.00|37800.00|2026-09-22 21:49:13' => [11, '2026-09-22 21:49:13', 'due', true, 1],
        '2026-09-22|1800.00|22680.00|2026-09-22 21:49:38' => [12, '2026-09-22 21:49:38', 'due', true, 1],
        '2026-09-06|270.00|3240.00|2026-09-22 22:14:05' => [13, '2026-09-22 22:14:05', 'due', false, 4],
        '2026-09-13|320.00|4000.00|2026-09-22 22:14:39' => [14, '2026-09-22 22:14:39', 'due', true, 4],
        '2026-09-20|230.00|2898.00|2026-09-22 22:15:10' => [15, '2026-09-22 22:15:10', 'due', true, 4],
        '2026-09-01|60.00|420.00|2026-09-22 22:19:29' => [16, '2026-09-22 22:19:29', 'cash', false, 3],
        '2026-09-02|30.00|210.00|2026-09-22 22:19:49' => [17, '2026-09-22 22:19:49', 'cash', false, 3],
        '2026-09-03|35.00|245.00|2026-09-22 22:20:06' => [18, '2026-09-22 22:20:06', 'cash', false, 3],
        '2026-09-06|45.00|540.00|2026-09-22 22:20:24' => [19, '2026-09-22 22:20:24', 'cash', false, 3],
        '2026-09-07|30.00|210.00|2026-09-22 22:20:42' => [20, '2026-09-22 22:21:59', 'cash', false, 3],
        '2026-09-09|30.00|210.00|2026-09-22 22:22:43' => [21, '2026-09-22 22:22:43', 'cash', false, 3],
        '2026-09-10|30.00|360.00|2026-09-22 22:23:02' => [22, '2026-09-22 22:23:02', 'cash', false, 3],
        '2026-09-12|40.00|280.00|2026-09-22 22:23:16' => [23, '2026-09-22 22:23:16', 'cash', false, 3],
        '2026-09-13|15.00|187.50|2026-09-22 22:23:36' => [24, '2026-09-22 22:23:36', 'cash', false, 3],
        '2026-09-14|30.00|375.00|2026-09-22 22:23:58' => [25, '2026-09-22 22:23:58', 'cash', false, 3],
        '2026-09-19|60.00|420.00|2026-09-22 22:24:23' => [26, '2026-09-22 22:24:23', 'cash', false, 3],
        '2026-09-23|3000.00|37800.00|2026-09-26 22:14:48' => [27, '2026-09-26 22:14:48', 'due', false, 1],
        '2026-09-27|1800.00|22320.00|2026-09-27 19:54:34' => [28, '2026-09-27 19:54:34', 'due', false, 1],
        '2026-09-23|70.00|490.00|2026-09-27 20:04:27' => [29, '2026-09-27 20:05:14', 'cash', false, 3],
        '2026-09-27|30.00|210.00|2026-09-27 20:04:46' => [30, '2026-09-27 20:05:05', 'cash', false, 3],
        '2026-09-29|3000.00|37500.00|2026-09-30 11:47:00' => [31, '2026-09-30 11:47:00', 'due', false, 1],
        '2026-09-29|270.00|3375.00|2026-09-30 11:47:23' => [32, '2026-09-30 11:47:23', 'due', false, 4],
        '2026-10-03|3000.00|36600.00|2026-10-08 11:09:34' => [33, '2026-10-08 11:09:34', 'due', true, 1],
        '2026-10-06|2400.00|29280.00|2026-10-08 11:10:11' => [34, '2026-10-08 11:10:11', 'due', true, 1],
        '2026-10-04|260.00|3172.00|2026-10-08 11:11:50' => [35, '2026-10-08 11:11:50', 'due', true, null],
        '2026-10-02|30.00|180.00|2026-10-08 11:13:10' => [36, '2026-10-08 11:13:10', 'due', false, 3],
        '2026-10-04|30.00|366.00|2026-10-08 11:13:55' => [37, '2026-10-08 11:13:55', 'due', false, 3],
        '2026-10-05|77.00|462.00|2026-10-08 11:14:38' => [38, '2026-10-08 11:15:10', 'due', false, 3],
        '2026-10-08|3000.00|36600.00|2026-10-13 12:37:32' => [39, '2026-10-13 12:37:32', 'due', true, 1],
        '2026-10-12|2700.00|32940.00|2026-10-13 12:38:20' => [40, '2026-10-13 12:38:20', 'due', true, 1],
        '2026-10-07|25.00|175.00|2026-10-13 12:40:17' => [41, '2026-10-13 12:40:17', 'due', true, 3],
        '2026-10-08|30.00|366.00|2026-10-13 12:40:44' => [42, '2026-10-13 12:40:44', 'due', true, 3],
        '2026-10-11|58.00|406.00|2026-10-13 12:42:05' => [43, '2026-10-13 12:42:05', 'due', true, 3],
        '2026-10-12|60.00|732.00|2026-10-13 12:42:28' => [44, '2026-10-13 12:42:28', 'due', true, 3],
        '2026-10-04|260.00|3172.00|2026-10-13 12:44:26' => [45, '2026-10-13 12:44:26', 'due', false, 4],
    ];

    /**
     * @var array<int, array{id: int, name: string, created_by: int|null, created_at: string, updated_at: string}>
     */
    private const RECOVERED_BUYERS = [
        ['id' => 1, 'name' => 'Mr.Yakub', 'created_by' => null, 'created_at' => '2026-09-13 02:04:21', 'updated_at' => '2026-09-13 02:04:21'],
        ['id' => 3, 'name' => 'Mr.Akter', 'created_by' => 1, 'created_at' => '2026-09-13 18:45:06', 'updated_at' => '2026-09-14 01:03:51'],
        ['id' => 4, 'name' => 'Mr.Shafin', 'created_by' => 3, 'created_at' => '2026-09-22 22:13:57', 'updated_at' => '2026-09-22 22:13:57'],
    ];

    /**
     * Brings back the buyer and in-charge based Egg Sales tables and moves the
     * ledger's rows into them. Stops before changing anything if a ledger row
     * has no recovered match, so a sale entered after the dump is never lost.
     */
    public function up(): void
    {
        foreach (['egg_sales', 'egg_buyers', 'egg_buyer_payments', 'egg_in_charges', 'egg_in_charge_transactions'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Egg Sales restore stopped, nothing was changed. The {$table} table already exists.");
            }
        }

        $ledgerRows = Schema::hasTable('egg_sale_transactions')
            ? DB::table('egg_sale_transactions')->orderBy('id')->get()
            : collect();

        $this->ensureEveryRowCanBeRestored($ledgerRows);

        try {
            $this->createTables();

            if ($ledgerRows->isNotEmpty()) {
                DB::transaction(fn () => $this->restoreRows($ledgerRows));
            }
        } catch (Throwable $exception) {
            $this->dropRestoredTables();

            throw $exception;
        }

        Schema::dropIfExists('egg_sale_transactions');
    }

    /**
     * Not reversible: the rows live only in the restored tables after this.
     */
    public function down(): void
    {
        //
    }

    /**
     * @param  Collection<int, object>  $ledgerRows
     */
    private function ensureEveryRowCanBeRestored(Collection $ledgerRows): void
    {
        $unmatchedIds = $ledgerRows
            ->filter(fn (object $row): bool => $row->type !== 'sold' || ! isset(self::RECOVERED_SALES[$this->ledgerKey($row)]))
            ->pluck('id');

        if ($unmatchedIds->isNotEmpty()) {
            throw new RuntimeException(
                'Egg Sales restore stopped, nothing was changed. These egg_sale_transactions rows are not in the recovered data: '.$unmatchedIds->implode(', ')
            );
        }
    }

    private function ledgerKey(object $row): string
    {
        return implode('|', [
            substr((string) $row->transaction_date, 0, 10),
            number_format((float) $row->quantity, 2, '.', ''),
            number_format((float) $row->amount, 2, '.', ''),
            (string) $row->created_at,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('egg_buyers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('phone')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('egg_buyer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_buyer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('egg_in_charges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('phone')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('egg_in_charge_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_in_charge_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('transaction_date');
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index(['egg_in_charge_id', 'type']);
        });

        Schema::create('egg_sales', function (Blueprint $table) {
            $table->id();
            $table->date('sale_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('sale_rate', 10, 2);
            $table->decimal('sale_amount', 12, 2);
            $table->foreignId('egg_buyer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_status')->default('cash');
            $table->foreignId('egg_in_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('sale_date');
        });
    }

    /**
     * @param  Collection<int, object>  $ledgerRows
     */
    private function restoreRows(Collection $ledgerRows): void
    {
        DB::table('egg_buyers')->insert(self::RECOVERED_BUYERS);

        $inChargeId = DB::table('egg_in_charges')->insertGetId([
            'name' => 'Mr.Akter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($ledgerRows as $row) {
            [$id, $updatedAt, $paymentStatus, $hasInCharge, $buyerId] = self::RECOVERED_SALES[$this->ledgerKey($row)];

            DB::table('egg_sales')->insert([
                'id' => $id,
                'sale_date' => $row->transaction_date,
                'quantity' => $row->quantity,
                'sale_rate' => $row->rate,
                'sale_amount' => $row->amount,
                'egg_buyer_id' => $buyerId,
                'payment_status' => $paymentStatus,
                'egg_in_charge_id' => $hasInCharge ? $inChargeId : null,
                'remarks' => $row->remarks,
                'created_by' => $row->created_by,
                'created_at' => $row->created_at,
                'updated_at' => $updatedAt,
            ]);
        }

        $restoredAmount = (float) DB::table('egg_sales')->sum('sale_amount');
        $ledgerAmount = (float) $ledgerRows->sum('amount');

        if (DB::table('egg_sales')->count() !== $ledgerRows->count() || abs($restoredAmount - $ledgerAmount) > 0.001) {
            throw new RuntimeException('Egg Sales restore stopped: the restored totals do not match the ledger.');
        }
    }

    private function dropRestoredTables(): void
    {
        Schema::dropIfExists('egg_sales');
        Schema::dropIfExists('egg_in_charge_transactions');
        Schema::dropIfExists('egg_in_charges');
        Schema::dropIfExists('egg_buyer_payments');
        Schema::dropIfExists('egg_buyers');
    }
};
