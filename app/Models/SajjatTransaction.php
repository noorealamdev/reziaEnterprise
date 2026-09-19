<?php

namespace App\Models;

use Database\Factories\SajjatTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SajjatTransaction extends Model
{
    /** @use HasFactory<SajjatTransactionFactory> */
    use HasFactory;

    public const TYPE_TOP_UP = 'top_up';

    public const TYPE_EXPENSE = 'expense';

    /** @var array<string, string> */
    public const WALLETS = [
        'bkash' => 'bKash',
        'cash' => 'Cash',
    ];

    protected $fillable = [
        'transaction_date',
        'type',
        'wallet',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What Sajjat has left in each wallet: everything topped up into it
     * minus everything spent from it, across all time. Computed live rather
     * than stored, same anti-drift principle as every other balance here.
     * Can go negative if he spends more than was given.
     *
     * @return array<string, float>
     */
    public static function balances(): array
    {
        $totals = static::query()
            ->selectRaw('wallet, type, SUM(amount) as total')
            ->groupBy('wallet', 'type')
            ->get();

        $balances = [];

        foreach (array_keys(self::WALLETS) as $wallet) {
            $topUps = (float) $totals->where('wallet', $wallet)->where('type', self::TYPE_TOP_UP)->sum('total');
            $expenses = (float) $totals->where('wallet', $wallet)->where('type', self::TYPE_EXPENSE)->sum('total');
            $balances[$wallet] = round($topUps - $expenses, 2);
        }

        return $balances;
    }
}
