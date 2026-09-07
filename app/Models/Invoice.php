<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'service_category_id',
        'invoice_number',
        'period_start',
        'period_end',
        'status',
        'vat_percent',
        'manual_amount',
        'manual_description',
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
            'vat_percent' => 'decimal:2',
            'manual_amount' => 'decimal:2',
        ];
    }

    /**
     * How much this invoice is for, before VAT/advance — a manual invoice
     * (a past paper bill entered directly, with no job entries behind it)
     * carries its own typed-in amount; every normal, generated invoice is
     * still summed live from its job entries, exactly as before.
     */
    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->manual_amount !== null
                ? (float) $this->manual_amount
                : (float) $this->jobEntries->sum('bill_amount'),
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function jobEntries(): HasMany
    {
        return $this->hasMany(JobEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
