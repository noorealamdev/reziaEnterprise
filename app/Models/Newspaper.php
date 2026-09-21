<?php

namespace App\Models;

use Database\Factories\NewspaperFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Newspaper extends Model
{
    /** @use HasFactory<NewspaperFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'journalist_name',
        'phone',
        'whatsapp',
        'monthly_amount',
        'is_active',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(NewspaperPayment::class);
    }

    /**
     * A tel: link that dials straight from a phone.
     */
    public function callUrl(): string
    {
        return 'tel:'.self::dialable($this->phone);
    }

    /**
     * A wa.me chat link, or null when no WhatsApp number was saved.
     */
    public function whatsappUrl(): ?string
    {
        if (! $this->whatsapp) {
            return null;
        }

        $digits = ltrim(self::dialable($this->whatsapp), '+');

        // Local Bangladeshi numbers (01XXXXXXXXX) need the country code for wa.me.
        if (str_starts_with($digits, '0')) {
            $digits = '88'.$digits;
        }

        return 'https://wa.me/'.$digits;
    }

    private static function dialable(string $number): string
    {
        return (str_starts_with(trim($number), '+') ? '+' : '').preg_replace('/\D+/', '', $number);
    }
}
