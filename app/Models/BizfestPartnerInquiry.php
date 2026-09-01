<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BizfestPartnerInquiry extends Model
{
    protected $fillable = [
        'programme',
        'inquiry_type',
        'company_name',
        'contact_name',
        'email',
        'phone',
        'tier_interest',
        'interests',
        'booth_package',
        'space_package',
        'booth_quantity',
        'message',
        'status',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    protected $casts = [
        'interests' => 'array',
        'booth_quantity' => 'integer',
    ];
}
