<?php

namespace App\Models;

use Database\Factories\EggInChargeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EggInCharge extends Model
{
    /** @use HasFactory<EggInChargeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'remarks',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(EggSale::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(EggInChargeTransaction::class);
    }

    /**
     * The full value of every sale under this In-Charge person's name —
     * Cash and Due alike. An In-Charge person (e.g. Mr. Akter, the egg
     * sales manager) owns their whole sales operation, not just the Cash
     * side of it — a Due sale is still their responsibility until the
     * buyer settles it, which is tracked separately via
     * EggBuyer::outstandingDue without changing anything here.
     */
    protected function totalSalesValue(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->sales()->sum('sale_amount'),
        );
    }

    protected function totalExpenses(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->transactions()->where('type', EggInChargeTransaction::TYPE_EXPENSE)->sum('amount'),
        );
    }

    protected function totalRemitted(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->transactions()->where('type', EggInChargeTransaction::TYPE_REMITTANCE)->sum('amount'),
        );
    }

    /**
     * How much this in-charge person still owes overall: the full value of
     * every sale attributed to them (Cash and Due alike — they own the
     * whole operation), minus whatever they've spent on the company's
     * behalf, minus whatever cash they've actually handed back — a running
     * balance across all time, not scoped to whatever Year/Month the
     * In-Charge Ledger happens to be filtered to, the same "avoid ledger
     * drift" principle already used for EggBuyer::outstandingDue.
     */
    protected function outstandingOwed(): Attribute
    {
        return Attribute::make(
            get: fn (): float => max(0.0, $this->totalSalesValue - $this->totalExpenses - $this->totalRemitted),
        );
    }
}
