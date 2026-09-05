<?php

namespace App\Models;

use Database\Factories\EggSaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EggSale extends Model
{
    /** @use HasFactory<EggSaleFactory> */
    use HasFactory;

    protected $fillable = [
        'sale_date',
        'quantity',
        'sale_rate',
        'sale_amount',
        'buyer_name',
        'remarks',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'quantity' => 'decimal:2',
            'sale_rate' => 'decimal:2',
            'sale_amount' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
