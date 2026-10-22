<?php

namespace App\Models;

use Database\Factories\EggSaleTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EggSaleTransaction extends Model
{
    /** @use HasFactory<EggSaleTransactionFactory> */
    use HasFactory;

    public const TYPE_RECEIVED = 'received';

    public const TYPE_SOLD = 'sold';

    public const TYPE_PAYMENT = 'payment';

    protected $fillable = [
        'transaction_date',
        'type',
        'quantity',
        'rate',
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
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Eggs on hand right now: everything received into this ledger minus
     * everything sold out of it, across all time. Computed live rather
     * than stored, same anti-drift principle as the Sajjat wallet. Can go
     * negative — that's a real discrepancy (more recorded sold than ever
     * received) worth surfacing, not hiding.
     */
    public static function quantityBalance(): float
    {
        $received = (float) static::where('type', self::TYPE_RECEIVED)->sum('quantity');
        $sold = (float) static::where('type', self::TYPE_SOLD)->sum('quantity');

        return round($received - $sold, 2);
    }

    /**
     * All-time money the sales Akter has made are worth — feeds the Egg
     * Purchases page's "Egg Business Profit" figure, same role
     * EggSale::sale_amount played before this redesign. Deliberately the
     * sale amount, not what's actually been remitted yet — profit is
     * recognized when the sale happens, same as every other revenue line
     * in this app, not when the cash physically reaches the company.
     */
    public static function totalRevenue(): float
    {
        return (float) static::where('type', self::TYPE_SOLD)->sum('amount');
    }

    /**
     * All-time cash Akter has actually handed over to the company. He
     * settles in weekly/monthly lump sums rather than per sale, so this is
     * tracked as its own running total rather than a status on each sale.
     */
    public static function totalPaidIn(): float
    {
        return (float) static::where('type', self::TYPE_PAYMENT)->sum('amount');
    }

    /**
     * What Akter still owes the company: every sale he's made minus every
     * payment he's handed over, clamped at zero — a lump sum that happens
     * to exceed what he owes just means he's settled up, not that the
     * company owes him something.
     */
    public static function outstandingDue(): float
    {
        return max(0.0, round(self::totalRevenue() - self::totalPaidIn(), 2));
    }
}
