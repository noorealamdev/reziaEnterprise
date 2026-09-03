<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiffinDepartmentItem extends Model
{
    protected $fillable = [
        'tiffin_department_id',
        'tiffin_item_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function tiffinDepartment(): BelongsTo
    {
        return $this->belongsTo(TiffinDepartment::class);
    }

    public function tiffinItem(): BelongsTo
    {
        return $this->belongsTo(TiffinItem::class);
    }
}
