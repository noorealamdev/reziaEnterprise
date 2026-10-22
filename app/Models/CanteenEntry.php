<?php

namespace App\Models;

use Database\Factories\CanteenEntryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanteenEntry extends Model
{
    /** @use HasFactory<CanteenEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'entry_date',
        'total_purchases',
        'total_profit',
        'daily_cost',
        'remarks',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Explicit Y-m-d format — the plain 'date' cast serializes to a
            // full datetime string, which SQLite (unlike MySQL) stores
            // as-is, breaking the entry_date uniqueness check's raw string
            // comparison against what's actually persisted.
            'entry_date' => 'date:Y-m-d',
            'total_purchases' => 'decimal:2',
            'total_profit' => 'decimal:2',
            'daily_cost' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Computed live, never stored — same anti-drift principle used
     * throughout this app (e.g. TiffinItemPurchase::profit).
     */
    protected function netProfit(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->total_profit - (float) $this->daily_cost, 2),
        );
    }
}
