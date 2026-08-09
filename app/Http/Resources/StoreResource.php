<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StorefrontTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Store */
class StoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        $subdomainHost = "{$this->slug}.{$platformDomain}";

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'business_name' => $this->name,
            'industry' => $this->merchant?->industry ?? 'other',
            'description' => $this->description ?? '',
            'brand_color' => $this->brand_color ?? '#0E7C66',
            'logo_url' => $this->logo_url,
            'contact_email' => $this->contact_email ?? $this->merchant?->owner?->email,
            'contact_phone' => $this->contact_phone,
            'business_location' => $this->business_location,
            'weekly_orders' => $this->weekly_orders,
            'payment_currencies' => $this->payment_currencies ?? [],
            'staff_count' => $this->staff_count,
            'physical_store_count' => $this->physical_store_count,
            'storefront_template_id' => $this->storefront_template_id ?? StorefrontTemplate::DEFAULT_ID,
            'subdomain' => $this->slug,
            'subdomain_host' => $subdomainHost,
            'primary_domain' => $this->primary_domain ?? $subdomainHost,
            'dealie_enabled' => (bool) ($this->dealie_enabled ?? true),
            'dealie_vendor_id' => $this->dealie_vendor_id ? (string) $this->dealie_vendor_id : null,
            'dealie_chat_mode' => $this->dealie_chat_mode ?? 'full_ai',
            'dealie_chat_config' => $this->dealie_chat_config ?? [
                'auto_approve_discount_percent' => 5.0,
                'offline_fallback_mode' => 'full_ai',
                'sound_alerts' => true,
                'email_alerts' => true,
            ],
        ];
    }
}
