<?php

namespace App\Models;

use Database\Factories\EggWasteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EggWaste extends Model
{
    /** @use HasFactory<EggWasteFactory> */
    use HasFactory;

    protected $fillable = [
        'waste_date',
        'quantity',
        'remarks',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waste_date' => 'date',
            'quantity' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
