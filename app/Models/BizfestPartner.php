<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BizfestPartner extends Model
{
    protected $fillable = [
        'programme',
        'name',
        'label',
        'logo_url',
        'website_url',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
