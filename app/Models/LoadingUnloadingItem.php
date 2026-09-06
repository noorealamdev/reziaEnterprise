<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoadingUnloadingItem extends Model
{
    protected $fillable = [
        'name',
        'unit_label',
        'is_active',
        'sort_order',
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
}
