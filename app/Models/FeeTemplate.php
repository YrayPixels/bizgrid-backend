<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeTemplate extends Model
{
    protected $fillable = [
        'school_id',
        'fee_category_id',
        'name',
        'description',
        'amount',
        'currency',
        'is_recurring',
        'is_optional',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_recurring' => 'boolean',
        'is_optional' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FeeAssignment::class);
    }
}
