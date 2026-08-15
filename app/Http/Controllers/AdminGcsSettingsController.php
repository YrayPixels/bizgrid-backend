<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Services\AdminAuditService;
use App\Services\GoogleCloudStorageClient;
use App\Services\PlatformGcsConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminGcsSettingsController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly PlatformGcsConfigService $gcsConfig,
        private readonly GoogleCloudStorageClient $gcs,
        private readonly AdminAuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->gcsConfig->adminConfig(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'driver' => 'nullable|string|in:local,gcs',
            'bucket' => 'nullable|string|max:222',
            'project_id' => 'nullable|string|max:128',
            'path_prefix' => 'nullable|string|max:120',
            'public_url' => 'nullable|string|max:500',
            'credentials' => 'nullable|string|max:20000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->gcsConfig->update($validator->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $this->audit->log($request, 'platform.gcs_settings.updated', 'platform_setting', null, [
            'driver' => $data['driver'],
            'using_cloud' => $data['using_cloud'],
            'configured' => $data['configured'],
            'bucket' => $data['bucket'],
        ]);

        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Storage settings updated.',
        ]);
    }

    public function probe(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->gcs->probe(),
        ]);
    }
}
