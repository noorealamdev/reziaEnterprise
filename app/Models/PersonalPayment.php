<?php

namespace App\Models;

use Database\Factories\PersonalPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalPayment extends Model
{
    /** @use HasFactory<PersonalPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'personal_contact_id',
        'payment_date',
        'amount',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(PersonalContact::class, 'personal_contact_id');
    }
}
