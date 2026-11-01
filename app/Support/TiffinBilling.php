<?php

namespace App\Support;

use App\Models\Company;

class TiffinBilling
{
    public const WASH_WORKER = 'Wash Worker';

    public const WASH_STAFF = 'Wash Staff';

    /**
     * Egg carries a Tiffin department's whole meal price. Ananta's Wash Workers
     * are the one exception: they are billed per item instead (see
     * config/tiffin.php).
     */
    public static function billsItem(?Company $company, string $departmentName, string $itemName): bool
    {
        return $itemName === 'Egg'
            || ($company?->code === 'AAL' && self::defaultItemRate($departmentName, $itemName) !== null);
    }

    public static function defaultItemRate(string $departmentName, string $itemName): ?float
    {
        $rate = config("tiffin.item_bill_rates.{$departmentName}.{$itemName}");

        return $rate === null ? null : (float) $rate;
    }

    /**
     * Ananta bills Wash Worker and Wash Staff on one document. Like Simba's
     * units this is a hardcoded exception for one company, not a generic
     * feature. Empty unless the company has both departments.
     *
     * @return array<int, int>
     */
    public static function washDepartmentIds(?Company $company): array
    {
        if ($company?->code !== 'AAL') {
            return [];
        }

        $ids = $company->tiffinDepartments()
            ->whereIn('name', [self::WASH_WORKER, self::WASH_STAFF])
            ->pluck('tiffin_departments.id')
            ->all();

        return count($ids) === 2 ? $ids : [];
    }
}
