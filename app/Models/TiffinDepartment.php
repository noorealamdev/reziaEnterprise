<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TiffinDepartment extends Model
{
    protected $fillable = [
        'name',
    ];

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_tiffin_departments');
    }

    public function departmentItems(): HasMany
    {
        return $this->hasMany(TiffinDepartmentItem::class)->orderBy('sort_order');
    }
}
