<?php

namespace App\Models;

use Database\Factories\NewspaperPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewspaperPayment extends Model
{
    /** @use HasFactory<NewspaperPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'newspaper_id',
        'for_month',
        'amount',
        'paid_on',
        'wallet',
        'sajjat_transaction_id',
        'remarks',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'paid_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function newspaper(): BelongsTo
    {
        return $this->belongsTo(Newspaper::class);
    }

    public function sajjatTransaction(): BelongsTo
    {
        return $this->belongsTo(SajjatTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
