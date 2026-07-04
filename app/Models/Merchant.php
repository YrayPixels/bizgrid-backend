<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'business_name',
        'slug',
        'contact_name',
        'email',
        'phone',
        'industry',
        'status',
        'subscription_plan',
        'subscription_status',
        'dodo_customer_id',
        'dodo_subscription_id',
        'subscription_renews_at',
        'sms_included_remaining',
        'sms_purchased_balance',
        'whatsapp_included_remaining',
        'whatsapp_purchased_balance',
        'ai_purchased_credits',
        'ai_credits_used_today',
        'ai_credits_date',
        'monthly_processed_ngn',
        'monthly_usage_period_start',
        'activated_at',
        'suspended_at',
        'suspension_reason',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'subscription_renews_at' => 'datetime',
            'ai_credits_date' => 'date',
            'monthly_usage_period_start' => 'date',
            'monthly_processed_ngn' => 'decimal:2',
            'tags' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MerchantNote::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
