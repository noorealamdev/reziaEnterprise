<?php

namespace App\Models;

use Database\Factories\PersonalContactFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalContact extends Model
{
    /** @use HasFactory<PersonalContactFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'factory_name',
        'phone',
        'remarks',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PersonalSale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PersonalPayment::class);
    }

    /**
     * Computed live from sales minus payments, never a stored counter —
     * the same anti-drift principle already used for Egg stock and Company
     * Purchase balances elsewhere in this app.
     */
    protected function balanceDue(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->sales()->sum('amount') - (float) $this->payments()->sum('amount'),
        );
    }
}
