<?php

declare(strict_types=1);

namespace App\Support;

final class AdminPermissions
{
    public const DASHBOARD = 'dashboard';

    public const MERCHANTS = 'merchants';

    public const ORDERS = 'orders';

    public const INQUIRIES = 'inquiries';

    public const BUILDER = 'builder';

    public const AGENT_LOGS = 'agent_logs';

    public const AI_SETTINGS = 'ai_settings';

    public const STOREFRONT_TEMPLATES = 'storefront_templates';

    public const AUDIT_LOG = 'audit_log';

    public const HEALTH = 'health';

    public const ADMINS = 'admins';

    public const PROFILE = 'profile';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DASHBOARD,
            self::MERCHANTS,
            self::ORDERS,
            self::INQUIRIES,
            self::BUILDER,
            self::AGENT_LOGS,
            self::AI_SETTINGS,
            self::STOREFRONT_TEMPLATES,
            self::AUDIT_LOG,
            self::HEALTH,
            self::ADMINS,
            self::PROFILE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            'super_admin' => self::all(),
            'billing' => [
                self::DASHBOARD,
                self::MERCHANTS,
                self::ORDERS,
                self::HEALTH,
                self::PROFILE,
            ],
            default => [
                self::DASHBOARD,
                self::MERCHANTS,
                self::ORDERS,
                self::INQUIRIES,
                self::BUILDER,
                self::AGENT_LOGS,
                self::STOREFRONT_TEMPLATES,
                self::PROFILE,
            ],
        };
    }

    /**
     * @param  list<mixed>|null  $keys
     * @return list<string>
     */
    public static function normalize(?array $keys, string $role): array
    {
        if ($role === 'super_admin') {
            return self::all();
        }

        $allowed = array_flip(self::all());
        $normalized = [];

        foreach ($keys ?? [] as $key) {
            if (! is_string($key) || ! isset($allowed[$key])) {
                continue;
            }
            // Non–super-admins cannot manage admins.
            if ($key === self::ADMINS) {
                continue;
            }
            $normalized[$key] = true;
        }

        $result = array_keys($normalized);

        if ($result === []) {
            return self::defaultsForRole($role);
        }

        if (! in_array(self::PROFILE, $result, true)) {
            $result[] = self::PROFILE;
        }

        return array_values($result);
    }

    /**
     * @param  list<string>|null  $stored
     * @return list<string>
     */
    public static function effective(?array $stored, ?string $role): array
    {
        $role = $role ?: 'support';

        if ($role === 'super_admin') {
            return self::all();
        }

        if ($stored === null || $stored === []) {
            return self::defaultsForRole($role);
        }

        return self::normalize($stored, $role);
    }
}
