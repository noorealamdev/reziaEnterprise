<?php

namespace App\Models;

use Database\Factories\PersonalSaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalSale extends Model
{
    /** @use HasFactory<PersonalSaleFactory> */
    use HasFactory;

    protected $fillable = [
        'personal_contact_id',
        'sale_date',
        'description',
        'amount',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(PersonalContact::class, 'personal_contact_id');
    }
}
