<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'logo_path',
    ];

    /**
     * The single row of app-wide settings — created on first access, since
     * there's only ever one business using this app (no multi-tenant
     * concept), so there's nothing to key it by.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
