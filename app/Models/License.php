<?php

namespace App\Models;

use Database\Factories\LicenseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class License extends Model
{
    /** @use HasFactory<LicenseFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'license_number',
        'issue_date',
        'expiry_date',
        'document_path',
        'remarks',
        'last_alerted_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'last_alerted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->expiry_date->isPast(),
        );
    }

    protected function documentUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->document_path ? Storage::disk('public')->url($this->document_path) : null,
        );
    }

    protected function documentIsPdf(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => str_ends_with((string) $this->document_path, '.pdf'),
        );
    }
}
