<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Store */
class StoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');
        $subdomainHost = "{$this->slug}.{$platformDomain}";

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'business_name' => $this->name,
            'industry' => $this->merchant?->industry ?? 'other',
            'description' => $this->description ?? '',
            'brand_color' => $this->brand_color ?? '#0E7C66',
            'logo_url' => $this->logo_url,
            'contact_email' => $this->contact_email ?? $this->merchant?->email,
            'contact_phone' => $this->contact_phone,
            'business_location' => $this->business_location,
            'weekly_orders' => $this->weekly_orders,
            'payment_currencies' => $this->payment_currencies ?? [],
            'staff_count' => $this->staff_count,
            'physical_store_count' => $this->physical_store_count,
            'storefront_template_id' => $this->storefront_template_id ?? 'ai_pick',
            'subdomain' => $this->slug,
            'subdomain_host' => $subdomainHost,
            'primary_domain' => $this->primary_domain ?? $subdomainHost,
        ];
    }
}
