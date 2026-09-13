<?php

namespace App\Models;

use Database\Factories\TiffinItemPurchaseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TiffinItemPurchase extends Model
{
    /** @use HasFactory<TiffinItemPurchaseFactory> */
    use HasFactory;

    protected $fillable = [
        'tiffin_item_id',
        'purchase_date',
        'quantity',
        'purchase_rate',
        'purchase_amount',
        'sale_rate',
        'supplier_name',
        'memo_path',
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
            'purchase_rate' => 'decimal:2',
            'purchase_amount' => 'decimal:2',
            'sale_rate' => 'decimal:2',
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

    protected function memoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->memo_path ? Storage::disk('public')->url($this->memo_path) : null,
        );
    }

    protected function memoIsPdf(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => str_ends_with((string) $this->memo_path, '.pdf'),
        );
    }

    /**
     * What this purchase is worth at the internal sale rate — computed
     * live, never stored, same anti-drift principle as purchase_amount
     * being the only rate figure actually persisted.
     */
    protected function saleAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->quantity * (float) $this->sale_rate, 2),
        );
    }

    /**
     * The egg business's own margin on this purchase — sale value minus
     * what was actually paid for it. Separate from Tiffin's profit
     * (bill vs cost on job entries), which is a different business.
     */
    protected function profit(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->saleAmount - (float) $this->purchase_amount, 2),
        );
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
