<?php

namespace App\Support;

use App\Models\AppBugReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ApiBugReporter
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'pin',
        'passphrase',
        'private_key',
        'secret',
        'token',
        'authorization',
        'api_key',
        'access_token',
        'refresh_token',
    ];

    public static function reportException(Request $request, Throwable $exception): void
    {
        self::createReport($request, [
            'title' => self::limit('API exception: '.$exception->getMessage(), 255),
            'summary' => self::limit(class_basename($exception).' thrown by '.$request->method().' '.$request->path(), 2000),
            'details' => json_encode(self::baseMetadata($request), JSON_PRETTY_PRINT),
            'stack_trace' => $exception->getTraceAsString(),
            'type' => 'bug',
            'severity' => 'critical',
            'metadata' => [
                ...self::baseMetadata($request),
                'handler' => 'backend_api',
                'failure_kind' => 'exception',
                'exception_class' => $exception::class,
            ],
        ]);
    }

    public static function reportFailedResponse(Request $request, int $statusCode): void
    {
        $severity = $statusCode >= 500 ? 'critical' : 'warning';
        $type = $statusCode >= 500 ? 'bug' : 'log';

        self::createReport($request, [
            'title' => self::limit("API HTTP {$statusCode}: {$request->method()} {$request->path()}", 255),
            'summary' => self::limit("Backend API returned HTTP {$statusCode}", 2000),
            'details' => json_encode([
                ...self::baseMetadata($request),
                'status_code' => $statusCode,
            ], JSON_PRETTY_PRINT),
            'type' => $type,
            'severity' => $severity,
            'metadata' => [
                ...self::baseMetadata($request),
                'handler' => 'backend_api',
                'failure_kind' => 'http_error',
                'status_code' => $statusCode,
            ],
        ]);
    }

    public static function shouldSkip(Request $request): bool
    {
        return str_contains($request->path(), 'bug-reports');
    }

    private static function createReport(Request $request, array $payload): void
    {
        if (!Schema::hasTable('app_bug_reports') || self::shouldSkip($request)) {
            return;
        }

        $throttleKey = ($payload['severity'] ?? 'warning').':'.($payload['title'] ?? '');
        if (self::isThrottled($throttleKey)) {
            return;
        }

        try {
            AppBugReport::create([
                'user_id' => $request->user()?->id,
                'wallet_address' => self::walletAddress($request),
                'type' => $payload['type'] ?? 'bug',
                'severity' => $payload['severity'] ?? 'warning',
                'status' => 'new',
                'title' => $payload['title'],
                'summary' => $payload['summary'] ?? null,
                'details' => $payload['details'] ?? null,
                'stack_trace' => $payload['stack_trace'] ?? null,
                'source' => 'backend-api',
                'app_version' => config('app.env').' Laravel '.app()->version(),
                'platform' => 'server',
                'device_info' => PHP_SAPI.' PHP '.PHP_VERSION,
                'metadata' => $payload['metadata'] ?? null,
            ]);
        } catch (Throwable) {
            // Reporting must never break the API response path.
        }
    }

    private static function baseMetadata(Request $request): array
    {
        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => optional($request->route())->getName(),
            'action' => optional($request->route())->getActionName(),
            'ip' => $request->ip(),
            'query' => self::sanitizeArray($request->query()),
            'input' => self::sanitizeArray($request->except(self::SENSITIVE_KEYS)),
            'user_agent' => self::limit((string) $request->userAgent(), 255),
        ];
    }

    private static function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::sanitizeArray($value);
            }
        }

        return $data;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private static function walletAddress(Request $request): ?string
    {
        $wallet = $request->input('wallet_address') ?? $request->query('wallet_address');

        return is_string($wallet) ? self::limit($wallet, 64) : null;
    }

    private static function limit(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }

    private static function isThrottled(string $key): bool
    {
        $cacheKey = 'api_bug_throttle:'.md5($key);

        if (Cache::has($cacheKey)) {
            return true;
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        return false;
    }
}
