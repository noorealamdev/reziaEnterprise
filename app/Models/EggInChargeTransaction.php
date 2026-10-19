<?php

namespace App\Models;

use Database\Factories\EggInChargeTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EggInChargeTransaction extends Model
{
    /** @use HasFactory<EggInChargeTransactionFactory> */
    use HasFactory;

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_REMITTANCE = 'remittance';

    protected $fillable = [
        'egg_in_charge_id',
        'type',
        'transaction_date',
        'amount',
        'description',
        'remarks',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function eggInCharge(): BelongsTo
    {
        return $this->belongsTo(EggInCharge::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
