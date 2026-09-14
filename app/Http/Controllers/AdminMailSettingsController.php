<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Services\AdminAuditService;
use App\Services\PlatformMailConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminMailSettingsController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly PlatformMailConfigService $mailConfig,
        private readonly AdminAuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->mailConfig->adminConfig(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'active_provider_id' => 'nullable|string|max:64',
            'delete_provider_id' => 'nullable|string|max:64',
            'activate' => 'nullable|boolean',
            'upsert_provider' => 'nullable|array',
            'upsert_provider.id' => 'nullable|string|max:64',
            'upsert_provider.name' => 'nullable|string|max:120',
            'upsert_provider.preset' => 'nullable|string|max:40',
            'upsert_provider.driver' => ['nullable', 'string', Rule::in(PlatformMailConfigService::ALLOWED_DRIVERS)],
            'upsert_provider.scheme' => 'nullable|string|max:20',
            'upsert_provider.host' => 'nullable|string|max:255',
            'upsert_provider.port' => 'nullable|integer|min:1|max:65535',
            'upsert_provider.username' => 'nullable|string|max:255',
            'upsert_provider.password' => 'nullable|string|max:500',
            'upsert_provider.from_address' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                if (! filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
                    $fail('The from address must be a valid email address.');
                }
            }],
            'upsert_provider.from_name' => 'nullable|string|max:120',
            // Legacy flat fields (still accepted).
            'mailer' => ['nullable', 'string', Rule::in(PlatformMailConfigService::ALLOWED_MAILERS)],
            'name' => 'nullable|string|max:120',
            'preset' => 'nullable|string|max:40',
            'scheme' => 'nullable|string|max:20',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:500',
            'from_address' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                if (! filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
                    $fail('The from address must be a valid email address.');
                }
            }],
            'from_name' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->mailConfig->update($validator->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $this->audit->log($request, 'platform.mail_settings.updated', 'platform_setting', null, [
            'mailer' => $data['mailer'],
            'configured' => $data['configured'],
            'active_provider_id' => $data['active_provider_id'],
            'providers_count' => count($data['providers'] ?? []),
            'host' => $data['host'],
            'from_address' => $data['from_address'],
            'source' => $data['source'],
        ]);

        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Mail settings updated.',
        ]);
    }

    public function probe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->mailConfig->probe($validator->validated()['to'] ?? null);

        return response()->json([
            'success' => $result['ok'],
            'data' => $result,
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 500);
    }
}
