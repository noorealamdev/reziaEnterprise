<?php

namespace App\Models;

use Database\Factories\EggBuyerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EggBuyer extends Model
{
    /** @use HasFactory<EggBuyerFactory> */
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

    /**
     * Cash actually received from this buyer, tracked against their running
     * balance rather than against one specific sale.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(EggBuyerPayment::class);
    }

    /**
     * How much this buyer still owes overall, across every Due sale ever
     * recorded for them minus every payment ever recorded — a running
     * balance, not scoped to whatever Year/Month the Buyer Summary happens
     * to be filtered to, the same "avoid ledger drift" principle already
     * used for CompanyPurchase::remainingBalance.
     */
    protected function outstandingDue(): Attribute
    {
        return Attribute::make(
            get: fn (): float => max(0.0, (float) $this->sales()->where('payment_status', 'due')->sum('sale_amount')
                - (float) $this->payments()->sum('amount')),
        );
    }
}
