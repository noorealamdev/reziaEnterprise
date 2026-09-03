<?php

namespace App\Models;

use Database\Factories\TiffinItemPurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiffinItemPurchase extends Model
{
    /** @use HasFactory<TiffinItemPurchaseFactory> */
    use HasFactory;

    protected $fillable = [
        'tiffin_item_id',
        'purchase_date',
        'quantity',
        'cost_rate',
        'cost_amount',
        'supplier_name',
        'remarks',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'quantity' => 'decimal:2',
            'cost_rate' => 'decimal:2',
            'cost_amount' => 'decimal:2',
        ];
    }

    public function tiffinItem(): BelongsTo
    {
        return $this->belongsTo(TiffinItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The purchase that governs an item's cost on a given date — matches by
     * name, same as the rest of Tiffin, since job_entries has no
     * tiffin_item_id column. Not just an exact-date match: if nothing was
     * purchased on that exact day, the most recent purchase on or before it
     * still applies (a rate stays in effect until a newer purchase updates
     * it — you don't buy eggs literally every single day).
     */
    public static function findFor(string $itemName, string $date): ?self
    {
        return static::whereHas('tiffinItem', fn ($query) => $query->where('name', $itemName))
            ->whereDate('purchase_date', '<=', $date)
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->first();
    }
}
