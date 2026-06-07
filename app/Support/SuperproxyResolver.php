<?php

namespace App\Support;

/**
 * Resolves HTTP proxy URL for Guzzle/Laravel Http from Superproxy-style env vars.
 *
 * @see docs/SUPERPROXY.md
 */
class SuperproxyResolver
{
    /**
     * Prefer explicit JUMIA_PROXY_URL, then Superproxy PROXY_URL / PROXY_HOST vars.
     */
    public static function httpProxyUrl(): ?string
    {
        $config = self::proxyConfig();

        return $config['proxy_url'] ?? null;
    }

    public static function guzzleOptions(): array
    {
        $config = self::proxyConfig();
        if (!$config) {
            return [];
        }

        $options = [
            'proxy' => [
                'http' => $config['proxy_url_with_auth'] ?? $config['proxy_url'],
                'https' => $config['proxy_url_with_auth'] ?? $config['proxy_url'],
            ],
        ];

        if (!empty($config['username']) && !empty($config['password']) && defined('CURLOPT_PROXYUSERPWD')) {
            $options['curl'] = [
                CURLOPT_PROXYUSERPWD => $config['username'] . ':' . $config['password'],
            ];

            if (defined('CURLOPT_PROXYAUTH') && defined('CURLAUTH_BASIC')) {
                $options['curl'][CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
            }

            if (defined('CURLOPT_HTTPPROXYTUNNEL')) {
                $options['curl'][CURLOPT_HTTPPROXYTUNNEL] = true;
            }
        }

        return $options;
    }

    public static function debugInfo(): array
    {
        $config = self::proxyConfig();
        if (!$config) {
            return [
                'configured' => false,
                'source' => self::proxySource(),
            ];
        }

        return [
            'configured' => true,
            'source' => self::proxySource(),
            'proxy_url' => $config['proxy_url'],
            'username_present' => !empty($config['username']),
            'password_present' => !empty($config['password']),
            'username_preview' => !empty($config['username']) ? substr($config['username'], 0, 8) . '...' : null,
        ];
    }

    /**
     * PROXY_URL=host:port:username:password or PROXY_HOST + PROXY_PORT + credentials.
     */
    public static function fromSuperproxyEnv(): ?string
    {
        $config = self::proxyConfig();

        return $config['proxy_url'] ?? null;
    }

    private static function proxyConfig(): ?array
    {
        $explicit = env('JUMIA_PROXY_URL') ?: env('OUTBOUND_PROXY_URL');
        if (is_string($explicit) && trim($explicit) !== '') {
            return self::fromFullProxyUrl(trim($explicit));
        }

        $proxyUrl = env('PROXY_URL');
        if (is_string($proxyUrl) && trim($proxyUrl) !== '') {
            $proxyUrl = trim($proxyUrl);
            if (!self::isPlaceholderValue($proxyUrl)) {
                if (str_contains($proxyUrl, '://') || str_contains($proxyUrl, '@')) {
                    return self::fromFullProxyUrl($proxyUrl);
                }

                $parts = explode(':', $proxyUrl, 4);
                if (count($parts) === 4) {
                    return self::buildProxyConfig($parts[0], $parts[1], $parts[2], $parts[3]);
                }
            }
        }

        $host = env('PROXY_HOST');
        $port = env('PROXY_PORT');
        if (is_string($host) && $host !== '' && is_string($port) && $port !== '') {
            $user = env('PROXY_USERNAME', '');
            $pass = env('PROXY_PASSWORD', '');

            return self::buildProxyConfig(
                $host,
                $port,
                is_string($user) ? $user : '',
                is_string($pass) ? $pass : ''
            );
        }

        return null;
    }

    public static function proxySource(): string
    {
        $explicit = env('JUMIA_PROXY_URL') ?: env('OUTBOUND_PROXY_URL');
        if (is_string($explicit) && trim($explicit) !== '') {
            return 'jumia_proxy_url';
        }

        if (self::fromSuperproxyEnv() !== null) {
            return 'superproxy_env';
        }

        return 'none';
    }

    private static function fromFullProxyUrl(string $proxyUrl): array
    {
        $parts = parse_url($proxyUrl);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])) {
            return ['proxy_url' => $proxyUrl];
        }

        $scheme = $parts['scheme'] ?? 'http';
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';

        return [
            'proxy_url' => sprintf('%s://%s:%s', $scheme, $parts['host'], $parts['port']),
            'proxy_url_with_auth' => ($user !== '' && $pass !== '')
                ? sprintf(
                    '%s://%s:%s@%s:%s',
                    $scheme,
                    rawurlencode($user),
                    rawurlencode($pass),
                    $parts['host'],
                    $parts['port']
                )
                : sprintf('%s://%s:%s', $scheme, $parts['host'], $parts['port']),
            'username' => $user,
            'password' => $pass,
        ];
    }

    private static function isPlaceholderValue(string $value): bool
    {
        return str_contains($value, 'your-zone-username')
            || str_contains($value, 'your-zone-password')
            || str_contains($value, 'your-password');
    }

    private static function buildProxyConfig(string $host, string $port, string $user, string $pass): array
    {
        return [
            'proxy_url' => sprintf('http://%s:%s', $host, $port),
            'proxy_url_with_auth' => ($user !== '' && $pass !== '')
                ? sprintf(
                    'http://%s:%s@%s:%s',
                    rawurlencode($user),
                    rawurlencode($pass),
                    $host,
                    $port
                )
                : sprintf('http://%s:%s', $host, $port),
            'username' => $user,
            'password' => $pass,
        ];
    }
}
