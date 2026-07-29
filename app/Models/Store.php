<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'name',
        'slug',
        'status',
        'primary_domain',
        'description',
        'brand_color',
        'logo_url',
        'contact_email',
        'contact_phone',
        'business_location',
        'weekly_orders',
        'payment_currencies',
        'payout_account_name',
        'payout_bank_name',
        'payout_account_number',
        'staff_count',
        'physical_store_count',
        'whatsapp_auto_reply_enabled',
        'tiktok_auto_reply_enabled',
        'notify_merchant_new_order',
        'notify_customer_order_confirmation',
        'notify_customer_payment_confirmation',
        'notify_merchant_low_stock',
        'notification_email',
        'customer_order_note',
        'sms_sender_name',
        'store_perks',
        'allow_local_delivery',
        'allow_pickup',
        'default_delivery_fee',
        'fulfilment_promise',
        'shipping_policy',
        'return_policy',
        'storefront_template_id',
        'preferred_storefront_template_id',
        'storefront_content',
        'draft_json',
        'published_json',
        'published_at',
        'storefront_generation_id',
        'products_count',
        'orders_count',
        'gross_revenue',
    ];

    protected static function booted(): void
    {
        static::updating(function (Store $store): void {
            // Platform migrate/restore writes preferred + template together.
            // Any other template change is a merchant (or AI) choice — drop the deferred preference.
            if (
                $store->isDirty('storefront_template_id')
                && ! $store->isDirty('preferred_storefront_template_id')
                && $store->preferred_storefront_template_id !== null
            ) {
                $store->preferred_storefront_template_id = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'gross_revenue' => 'decimal:2',
            'payment_currencies' => 'array',
            'storefront_content' => 'array',
            'draft_json' => 'array',
            'published_json' => 'array',
            'published_at' => 'datetime',
            'whatsapp_auto_reply_enabled' => 'boolean',
            'tiktok_auto_reply_enabled' => 'boolean',
            'notify_merchant_new_order' => 'boolean',
            'notify_customer_order_confirmation' => 'boolean',
            'notify_customer_payment_confirmation' => 'boolean',
            'notify_merchant_low_stock' => 'boolean',
            'allow_local_delivery' => 'boolean',
            'allow_pickup' => 'boolean',
            'default_delivery_fee' => 'decimal:2',
            'store_perks' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(StoreCustomer::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(StoreVisit::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(StoreCategory::class);
    }

    public function socialConnections(): HasMany
    {
        return $this->hasMany(StoreSocialConnection::class);
    }

    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function customerConversations(): HasMany
    {
        return $this->hasMany(CustomerConversation::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(StoreDomain::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(StoreLocation::class);
    }
}
