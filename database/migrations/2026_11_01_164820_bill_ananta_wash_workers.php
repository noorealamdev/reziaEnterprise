<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Wash Workers were saved with no bill amount, though Ananta is billed for
     * them: Banana at 6 and Milk at 22. Only entries not yet on an invoice are
     * priced, so no bill already sent ever changes.
     */
    public function up(): void
    {
        $companyId = DB::table('companies')->where('code', 'AAL')->value('id');
        $departmentId = DB::table('tiffin_departments')->where('name', 'Wash Worker')->value('id');

        if (! $companyId || ! $departmentId) {
            return;
        }

        foreach (['Banana' => 6, 'Milk' => 22] as $item => $rate) {
            DB::table('job_entries')
                ->where('company_id', $companyId)
                ->where('tiffin_department_id', $departmentId)
                ->where('supply_type', $item)
                ->whereNull('invoice_id')
                ->where('bill_amount', 0)
                ->update([
                    'bill_rate' => $rate,
                    'bill_amount' => DB::raw("ROUND(quantity * {$rate}, 2)"),
                    'profit_amount' => DB::raw("ROUND(quantity * {$rate}, 2) - cost_amount - COALESCE(shipment_tiffin_cost, 0)"),
                ]);
        }
    }

    /**
     * Not reversible: restore a database backup to undo this.
     */
    public function down(): void
    {
        //
    }
};
