<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Merchant extends Model
{
    use HasFactory;

    /**
     * The subscription statuses the system actually writes.
     *
     * `trialing` and `on_hold` are the canonical spellings — Dodo's webhooks use them
     * and so does DodoPaymentsService. Admin previously offered `trial` and `past_due`,
     * which nothing else in the system ever produced or understood.
     */
    public const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'on_hold', 'cancelled'];

    protected $fillable = [
        'owner_user_id',
        'business_name',
        'slug',
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

    public function staff(): HasMany
    {
        return $this->hasMany(MerchantStaff::class);
    }

    public function staffInvites(): HasMany
    {
        return $this->hasMany(MerchantStaffInvite::class);
    }

    public function hasCompletedOnboarding(): bool
    {
        if (array_key_exists('stores_count', $this->attributes)) {
            return (int) $this->attributes['stores_count'] > 0;
        }

        if ($this->relationLoaded('stores')) {
            return $this->stores->isNotEmpty();
        }

        return $this->stores()->exists();
    }

    /**
     * Create a pending merchant shell at signup so admin can see incomplete onboarding.
     */
    public static function ensurePendingForUser(User $user): self
    {
        $existing = static::where('owner_user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $displayName = filled($user->name)
            ? (string) $user->name
            : (string) Str::before((string) $user->email, '@');
        if ($displayName === '') {
            $displayName = 'Merchant';
        }

        $base = Str::slug($displayName) ?: 'merchant';
        $slug = $base;
        $suffix = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return static::create(array_merge([
            'owner_user_id' => $user->id,
            'business_name' => $displayName,
            'slug' => $slug,
            'status' => 'pending',
        ], static::defaultTrialSubscriptionAttributes()));
    }

    /**
     * Starter plan on a local free trial — used for every new merchant shell.
     * The trial clock starts at merchant signup (`created_at` + trial_days).
     * `subscription_renews_at` is a mirrored end date for billing UI.
     * Configure Dodo products with 0 trial days so checkout starts paid billing immediately.
     *
     * @return array{subscription_plan: string, subscription_status: string, subscription_renews_at: \Illuminate\Support\Carbon}
     */
    public static function defaultTrialSubscriptionAttributes(): array
    {
        $trialDays = static::configuredTrialDays();

        return [
            'subscription_plan' => (string) config('dodopayments.default_plan', 'starter'),
            'subscription_status' => 'trialing',
            'subscription_renews_at' => now()->addDays($trialDays),
        ];
    }

    public static function configuredTrialDays(): int
    {
        return max(1, (int) config('dodopayments.trial_days', 14));
    }

    /**
     * End of the local no-card trial, measured from merchant signup.
     */
    public function localTrialEndsAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->created_at === null) {
            return null;
        }

        return $this->created_at->copy()->addDays(static::configuredTrialDays());
    }

    /**
     * Whether the merchant may keep a storefront live / publish changes.
     * Active (or on-hold) paid subs, and in-window local trials, qualify.
     */
    public function canAccessLiveStorefront(): bool
    {
        if (in_array($this->subscription_status, ['active', 'on_hold'], true)) {
            return true;
        }

        if ($this->subscription_status !== 'trialing') {
            return false;
        }

        // Once Dodo has a subscription, trust that over the local clock.
        if (filled($this->dodo_subscription_id)) {
            return true;
        }

        $trialEndsAt = $this->localTrialEndsAt();

        return $trialEndsAt !== null && $trialEndsAt->isFuture();
    }

    /**
     * Local no-card trial that has passed its end date without a Dodo subscription.
     */
    public function isExpiredLocalTrial(): bool
    {
        if ($this->subscription_status !== 'trialing' || filled($this->dodo_subscription_id)) {
            return false;
        }

        $trialEndsAt = $this->localTrialEndsAt();

        return $trialEndsAt === null || $trialEndsAt->isPast();
    }

    /**
     * Promote pending merchants to active (e.g. after onboarding).
     * Suspended accounts are left alone.
     */
    public function ensureActive(): void
    {
        if ($this->status === 'suspended') {
            return;
        }

        $dirty = false;

        if ($this->status !== 'active') {
            $this->status = 'active';
            $dirty = true;
        }

        if ($this->activated_at === null) {
            $this->activated_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $this->suspended_at = null;
            $this->suspension_reason = null;
            $this->save();
        }
    }
}
