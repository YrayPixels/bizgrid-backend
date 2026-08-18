<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Services\AdminAuditService;
use App\Services\PlatformWhatsAppConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminWhatsAppSettingsController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly PlatformWhatsAppConfigService $whatsappConfig,
        private readonly AdminAuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->whatsappConfig->adminConfig(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'graph_version' => 'nullable|string|max:20',
            'platform_phone_number_id' => 'nullable|string|max:80',
            'verify_token' => 'nullable|string|max:255',
            'app_secret' => 'nullable|string|max:255',
            'platform_access_token' => 'nullable|string|max:5000',
            'webhook_url' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $this->whatsappConfig->update($validator->validated());

        $this->audit->log($request, 'platform.whatsapp_settings.updated', 'platform_setting', null, [
            'webhook_configured' => $data['webhook_configured'],
            'platform_configured' => $data['platform_configured'],
            'platform_phone_number_id' => $data['platform_phone_number_id'],
            'webhook_url' => $data['webhook_url'],
        ]);

        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'WhatsApp settings updated.',
        ]);
    }
}
