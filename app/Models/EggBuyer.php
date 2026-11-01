<?php

namespace App\Models;

use Database\Factories\EggBuyerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class EggBuyer extends Model
{
    /** @use HasFactory<EggBuyerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'remarks',
        'is_active',
        'uses_cash_wallet',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'uses_cash_wallet' => 'boolean',
        ];
    }

    /**
     * The one buyer who hands cash to the office instead of being billed.
     */
    public static function cashWalletBuyer(): ?self
    {
        return static::where('uses_cash_wallet', true)->orderBy('id')->first();
    }

    /**
     * The cash this buyer is holding: everything sold minus everything handed
     * to the office. Computed live rather than stored, same anti-drift
     * principle as every other balance here.
     */
    public function walletBalance(): float
    {
        return round((float) $this->sales()->sum('sale_amount') - (float) $this->cashHandovers()->sum('amount'), 2);
    }

    /**
     * Whether this much can come off the buyer's sales without the wallet
     * going below zero. Buyers on monthly bills have no wallet to protect.
     */
    public function canLoseSalesAmount(float $amount): bool
    {
        return ! $this->uses_cash_wallet || round($this->walletBalance() - $amount, 2) >= 0;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(EggSale::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(EggInvoice::class);
    }

    public function invoicePayments(): HasManyThrough
    {
        return $this->hasManyThrough(EggInvoicePayment::class, EggInvoice::class);
    }

    public function cashHandovers(): HasMany
    {
        return $this->hasMany(EggCashHandover::class);
    }
}
