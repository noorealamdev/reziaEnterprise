<?php

namespace App\Models;

use Database\Factories\CompanyPurchaseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CompanyPurchase extends Model
{
    /** @use HasFactory<CompanyPurchaseFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'purchase_date',
        'description',
        'bill_number',
        'quantity',
        'rate',
        'amount',
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
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Invoice payments recorded as a "Bill Adjustment" against this
     * purchase — each one draws down how much of this bill is still
     * available to offset a future invoice.
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * Computed live from the full amount minus every adjustment ever
     * recorded against it, rather than a stored counter — the same
     * "avoid ledger drift" principle already used for Egg stock.
     */
    protected function remainingBalance(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->amount - (float) $this->adjustments()->sum('amount'),
        );
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
}
