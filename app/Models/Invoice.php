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
        'manual_cost_amount',
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
            'manual_cost_amount' => 'decimal:2',
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

    /**
     * Only a generated bill can have custom dates — a past paper bill stores
     * its single bill date in both period columns but is still a month's bill.
     */
    protected function isCustomPeriod(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->manual_amount === null && ! (
                $this->period_start->isSameDay($this->period_start->copy()->startOfMonth())
                && $this->period_end->isSameDay($this->period_start->copy()->endOfMonth())
            ),
        );
    }

    /**
     * "October 2026" for a normal bill, otherwise the exact dates chosen.
     */
    protected function periodLabel(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (! $this->isCustomPeriod) {
                    return $this->period_start->format('F Y');
                }

                $from = $this->period_start->format('d-M-Y');
                $to = $this->period_end->format('d-M-Y');

                return $from === $to ? $from : "{$from} to {$to}";
            },
        );
    }

    /**
     * The month a bill counts under on the Bill Statement. A 25 Sep to 25 Oct
     * bill is October's bill, so it belongs to the month its dates end in.
     */
    protected function statementMonth(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->isCustomPeriod ? $this->period_end->copy()->startOfMonth() : $this->period_start,
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
