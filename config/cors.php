<?php

/**
 * CORS for the StoreHause API.
 *
 * Storefronts and the merchant app run on many hosts (apex, www, subdomains,
 * custom domains). With credentials disabled, allowing all origins is correct.
 *
 * Do not set STOREHAUSE_CORS_ORIGINS in production unless you fully understand
 * multi-tenant origin requirements — a partial allowlist will break www vs apex.
 */

$platformDomain = strtolower(trim((string) env('STOREHAUSE_PLATFORM_DOMAIN', 'bizgrid.shop')));
$platformDomain = preg_replace('/^www\./', '', $platformDomain) ?: 'bizgrid.shop';

$originPatterns = [];
$escaped = preg_quote($platformDomain, '/');
// https://bizgrid.shop, https://www.bizgrid.shop, https://shop.bizgrid.shop
$originPatterns[] = '#^https?://([a-z0-9-]+\.)*'.$escaped.'$#i';

if (env('APP_ENV', 'production') === 'local') {
    $originPatterns[] = '#^https?://([a-z0-9-]+\.)?localhost(:\d+)?$#i';
    $originPatterns[] = '#^https?://([a-z0-9-]+\.)?127\.0\.0\.1(:\d+)?$#i';
}

$extraPatterns = env('STOREHAUSE_CORS_ORIGIN_PATTERNS');
if (filled($extraPatterns)) {
    foreach (explode(',', (string) $extraPatterns) as $pattern) {
        $pattern = trim($pattern);
        if ($pattern !== '') {
            $originPatterns[] = $pattern;
        }
    }
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],

    'allowed_methods' => ['*'],

    // Always open — required for www + merchant subdomains + custom domains.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => $originPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 60 * 60 * 24,

    'supports_credentials' => false,

];
