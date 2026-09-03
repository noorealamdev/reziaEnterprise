<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TiffinItem extends Model
{
    protected $fillable = [
        'name',
        'unit_label',
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

    public function departmentItems(): HasMany
    {
        return $this->hasMany(TiffinDepartmentItem::class);
    }
}
