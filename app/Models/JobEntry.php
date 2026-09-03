<?php

namespace App\Models;

use Database\Factories\JobEntryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobEntry extends Model
{
    /** @use HasFactory<JobEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'service_category_id',
        'tiffin_department_id',
        'in_charge_id',
        'entry_date',
        'supply_type',
        'buyer',
        'style',
        'floor',
        'challan_no',
        'company_adv_payment',
        'quantity',
        'cost_rate',
        'bill_rate',
        'cost_amount',
        'bill_amount',
        'is_off_day',
        'remarks',
        'invoice_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'company_adv_payment' => 'decimal:2',
            'quantity' => 'decimal:2',
            'cost_rate' => 'decimal:2',
            'bill_rate' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'bill_amount' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'is_off_day' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (JobEntry $jobEntry): void {
            $jobEntry->profit_amount = (float) $jobEntry->bill_amount - (float) $jobEntry->cost_amount;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function tiffinDepartment(): BelongsTo
    {
        return $this->belongsTo(TiffinDepartment::class);
    }

    public function inCharge(): BelongsTo
    {
        return $this->belongsTo(InCharge::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function isBilled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->invoice_id !== null,
        );
    }
}
