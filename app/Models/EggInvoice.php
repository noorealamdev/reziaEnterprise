<?php

namespace App\Models;

use Database\Factories\EggInvoiceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class EggInvoice extends Model
{
    /** @use HasFactory<EggInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'egg_buyer_id',
        'invoice_number',
        'period_start',
        'period_end',
        'status',
        'paid_at',
        'remarks',
        'signed_copy_path',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'date',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(EggBuyer::class, 'egg_buyer_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(EggSale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EggInvoicePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->sales()->sum('sale_amount'), 2),
        );
    }

    protected function amountPaid(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->payments()->sum('amount'), 2),
        );
    }

    protected function balanceDue(): Attribute
    {
        return Attribute::make(
            get: fn (): float => max(0.0, round($this->total - $this->amountPaid, 2)),
        );
    }

    protected function isWholeMonth(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->period_start->isSameDay($this->period_start->copy()->startOfMonth())
                && $this->period_end->isSameDay($this->period_start->copy()->endOfMonth()),
        );
    }

    /**
     * "October 2026" for a whole month, otherwise the exact dates chosen.
     */
    protected function periodLabel(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if ($this->isWholeMonth) {
                    return $this->period_start->format('F Y');
                }

                $from = $this->period_start->format('d-M-Y');
                $to = $this->period_end->format('d-M-Y');

                return $from === $to ? $from : "{$from} to {$to}";
            },
        );
    }

    /**
     * Status always follows the recorded payments and is never set by hand,
     * the same rule factory invoices use.
     */
    public function refreshStatus(): void
    {
        $paid = $this->amountPaid;

        if ($paid <= 0) {
            $this->update(['status' => 'due', 'paid_at' => null]);
        } elseif ($paid + 0.01 >= $this->total) {
            $this->update(['status' => 'paid', 'paid_at' => $this->payments()->max('paid_on')]);
        } else {
            $this->update(['status' => 'partial', 'paid_at' => null]);
        }
    }

    public static function nextNumberFor(EggBuyer $buyer, Carbon $month): string
    {
        $base = sprintf('RE/EGG/B%d/%s', $buyer->id, $month->format('mY'));
        $number = $base;
        $suffix = 2;

        while (static::where('invoice_number', $number)->exists()) {
            $number = "{$base}-{$suffix}";
            $suffix++;
        }

        return $number;
    }
}
