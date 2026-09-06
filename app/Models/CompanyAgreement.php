<?php

namespace App\Models;

use Database\Factories\CompanyAgreementFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CompanyAgreement extends Model
{
    /** @use HasFactory<CompanyAgreementFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'start_date',
        'end_date',
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
            'start_date' => 'date',
            'end_date' => 'date',
            'last_alerted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->end_date->isPast(),
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
