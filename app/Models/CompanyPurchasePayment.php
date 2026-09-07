<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CompanyPurchasePayment extends Model
{
    protected $fillable = [
        'company_purchase_id',
        'amount',
        'paid_on',
        'receipt_path',
        'remarks',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function companyPurchase(): BelongsTo
    {
        return $this->belongsTo(CompanyPurchase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function receiptUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->receipt_path ? Storage::disk('public')->url($this->receipt_path) : null,
        );
    }

    protected function receiptIsPdf(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => str_ends_with((string) $this->receipt_path, '.pdf'),
        );
    }
}
