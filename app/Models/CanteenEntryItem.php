<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanteenEntryItem extends Model
{
    /**
     * The columns of the canteen's daily spreadsheet, in its order, with the
     * rates its formulas use. A null purchase rate means the item has no
     * purchase (Drinks + Others); a null profit rate means its profit is typed
     * in each day rather than calculated (Rice).
     *
     * @var array<string, array{label: string, purchase_rate: ?float, profit_rate: ?float}>
     */
    public const ITEMS = [
        'bakery_1' => ['label' => 'Bekary-1 (Borkot)', 'purchase_rate' => 8, 'profit_rate' => 2],
        'bakery_2' => ['label' => 'Bekary-2 (Amin)', 'purchase_rate' => 8, 'profit_rate' => 2],
        'porota' => ['label' => 'Porota', 'purchase_rate' => 8, 'profit_rate' => 2],
        'vaji' => ['label' => 'Vaji', 'purchase_rate' => 8, 'profit_rate' => 2],
        'singara' => ['label' => 'Singara', 'purchase_rate' => 8, 'profit_rate' => 2],
        'khuder_vat' => ['label' => 'Khuder Vat', 'purchase_rate' => 40, 'profit_rate' => 10],
        'rice' => ['label' => 'Rice', 'purchase_rate' => 47, 'profit_rate' => null],
        'cha' => ['label' => 'Cha', 'purchase_rate' => 350, 'profit_rate' => 150],
        'drinks_others' => ['label' => 'Drinks+Others', 'purchase_rate' => null, 'profit_rate' => 2.5],
    ];

    protected $fillable = [
        'canteen_entry_id',
        'item',
        'quantity',
        'purchase_rate',
        'purchase_amount',
        'profit_rate',
        'profit_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'purchase_rate' => 'decimal:2',
            'purchase_amount' => 'decimal:2',
            'profit_rate' => 'decimal:2',
            'profit_amount' => 'decimal:2',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CanteenEntry::class, 'canteen_entry_id');
    }

    public static function hasPurchase(string $item): bool
    {
        return self::ITEMS[$item]['purchase_rate'] !== null;
    }

    public static function hasTypedProfit(string $item): bool
    {
        return self::ITEMS[$item]['profit_rate'] === null;
    }

    /**
     * The spreadsheet's per-item formulas: purchase = quantity x buy rate, and
     * profit = quantity x profit rate, or the typed amount for Rice.
     *
     * @param  array{quantity?: mixed, purchase_rate?: mixed, profit_rate?: mixed, profit_amount?: mixed}  $input
     * @return array{quantity: float, purchase_rate: ?float, purchase_amount: float, profit_rate: ?float, profit_amount: float}
     */
    public static function calculate(string $item, array $input): array
    {
        $number = fn (string $field): float => is_numeric($input[$field] ?? null) ? (float) $input[$field] : 0.0;
        $quantity = $number('quantity');
        $purchaseRate = self::hasPurchase($item) ? $number('purchase_rate') : null;
        $profitRate = self::hasTypedProfit($item) ? null : $number('profit_rate');

        return [
            'quantity' => $quantity,
            'purchase_rate' => $purchaseRate,
            'purchase_amount' => round($quantity * (float) $purchaseRate, 2),
            'profit_rate' => $profitRate,
            'profit_amount' => $profitRate === null ? round($number('profit_amount'), 2) : round($quantity * $profitRate, 2),
        ];
    }

    /**
     * The rates for a new day: whatever was last recorded for each item, or the
     * spreadsheet's rate before anything is recorded. A one-off zero, like a
     * day's purchase that wasn't charged, is never carried forward.
     *
     * @return array<string, array{purchase_rate: ?float, profit_rate: ?float}>
     */
    public static function latestRates(): array
    {
        $rates = [];

        foreach (self::ITEMS as $item => $defaults) {
            $rates[$item] = [
                'purchase_rate' => self::latestRate($item, 'purchase_rate', $defaults['purchase_rate']),
                'profit_rate' => self::latestRate($item, 'profit_rate', $defaults['profit_rate']),
            ];
        }

        return $rates;
    }

    private static function latestRate(string $item, string $column, ?float $default): ?float
    {
        if ($default === null) {
            return null;
        }

        $latest = self::query()
            ->join('canteen_entries', 'canteen_entries.id', '=', 'canteen_entry_items.canteen_entry_id')
            ->where('item', $item)
            ->where($column, '>', 0)
            ->orderByDesc('canteen_entries.entry_date')
            ->value($column);

        return $latest !== null ? (float) $latest : $default;
    }
}
