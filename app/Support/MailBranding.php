<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Store;

final class MailBranding
{
    /**
     * @return array{
     *     name: string,
     *     logo_url: string,
     *     app_url: string,
     *     primary_color: string,
     *     support_email: string
     * }
     */
    public static function platform(): array
    {
        $appUrl = rtrim((string) config('storehause.app_url', config('app.url')), '/');

        return [
            'name' => (string) config('storehause.brand_name', config('app.name', 'Bizgrid')),
            'logo_url' => (string) config('storehause.mail_logo_url', $appUrl.'/bizgridlogo.png'),
            'app_url' => $appUrl,
            'admin_app_url' => rtrim((string) config('storehause.admin_app_url', $appUrl), '/'),
            'primary_color' => (string) config('storehause.mail_primary_color', '#0d9488'),
            'support_email' => (string) config('mail.from.address', 'hello@example.com'),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     logo_url: string|null,
     *     app_url: string,
     *     primary_color: string,
     *     support_email: string|null
     * }
     */
    public static function store(Store $store): array
    {
        $platform = self::platform();

        return [
            'name' => $store->name,
            'logo_url' => filled($store->logo_url) ? (string) $store->logo_url : null,
            'app_url' => $platform['app_url'],
            'primary_color' => $platform['primary_color'],
            'support_email' => filled($store->contact_email) ? (string) $store->contact_email : null,
        ];
    }
}
