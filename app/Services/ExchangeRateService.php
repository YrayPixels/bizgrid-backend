<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetch and cache NGN/USD exchange rates for deducting from user accounts (e.g. Jumia orders in NGN).
 * Uses free API: https://open.er-api.com (no key required). Override with EXCHANGE_RATE_API_URL in .env if needed.
 */
class ExchangeRateService
{
    private const CACHE_TTL_SECONDS = 3600; // 1 hour

    public static function getUsdToNgn(): ?float
    {
        $cacheKey = 'exchange_rate:usd_ngn';
        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () {
            $url = config('services.exchange_rate.url', 'https://open.er-api.com/v6/latest/USD');
            $response = Http::timeout(10)->get($url);
            if (!$response->successful()) {
                return null;
            }
            $data = $response->json();
            $rates = $data['rates'] ?? [];
            return isset($rates['NGN']) ? (float) $rates['NGN'] : null;
        });
    }

    /** NGN per 1 USD (e.g. 1380.84) */
    public static function ngnPerUsd(): ?float
    {
        return self::getUsdToNgn();
    }

    /** USD per 1 NGN (for deducting NGN amount in USD equivalent) */
    public static function usdPerNgn(): ?float
    {
        $ngnPerUsd = self::getUsdToNgn();
        if ($ngnPerUsd === null || $ngnPerUsd <= 0) {
            return null;
        }
        return 1.0 / $ngnPerUsd;
    }

    /** Convert NGN amount to USD (e.g. for deduction in USD). */
    public static function ngnToUsd(float $amountNgn): ?float
    {
        $usdPerNgn = self::usdPerNgn();
        if ($usdPerNgn === null) {
            return null;
        }
        return round($amountNgn * $usdPerNgn, 4);
    }
}
