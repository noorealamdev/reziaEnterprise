<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address',
        'contact_person',
        'phone',
        'email',
        'bepza_reg_no',
        'is_active',
        'tiffin_bill_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tiffin_bill_rate' => 'decimal:2',
        ];
    }

    public function jobEntries(): HasMany
    {
        return $this->hasMany(JobEntry::class);
    }

    public function serviceCategories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'company_service_categories');
    }

    public function tiffinDepartments(): BelongsToMany
    {
        return $this->belongsToMany(TiffinDepartment::class, 'company_tiffin_departments');
    }

    public function companyPurchases(): HasMany
    {
        return $this->hasMany(CompanyPurchase::class);
    }
}
