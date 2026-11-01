<?php

namespace App\Models;

use Database\Factories\EggCashHandoverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EggCashHandover extends Model
{
    /** @use HasFactory<EggCashHandoverFactory> */
    use HasFactory;

    protected $fillable = [
        'egg_buyer_id',
        'handed_on',
        'amount',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handed_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(EggBuyer::class, 'egg_buyer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
