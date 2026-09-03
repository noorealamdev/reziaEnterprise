<?php

namespace App\Models;

use Database\Factories\InChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InCharge extends Model
{
    /** @use HasFactory<InChargeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function jobEntries(): HasMany
    {
        return $this->hasMany(JobEntry::class);
    }
}
