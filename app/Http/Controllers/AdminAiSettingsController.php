<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Services\AdminAuditService;
use App\Services\AiChatClient;
use App\Services\PlatformAiConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminAiSettingsController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly PlatformAiConfigService $aiConfig,
        private readonly AdminAuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->aiConfig->adminConfig(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'sometimes|string|in:openai,deepseek,gemini',
            'openai_api_key' => 'nullable|string|max:500',
            'deepseek_api_key' => 'nullable|string|max:500',
            'gemini_api_key' => 'nullable|string|max:4000',
            'gemini_auth' => 'nullable|string|in:api_key,vertex',
            'gemini_location' => 'nullable|string|max:40',
            'openai_chat_model' => ['nullable', 'string', 'max:120', Rule::in($this->aiConfig->allowedChatModels('openai'))],
            'deepseek_chat_model' => ['nullable', 'string', 'max:120', Rule::in($this->aiConfig->allowedChatModels('deepseek'))],
            'gemini_chat_model' => ['nullable', 'string', 'max:120', Rule::in($this->aiConfig->allowedChatModels('gemini'))],
            'openai_vision_model' => ['nullable', 'string', 'max:120', Rule::in($this->aiConfig->allowedVisionModels('openai'))],
            'gemini_vision_model' => ['nullable', 'string', 'max:120', Rule::in($this->aiConfig->allowedVisionModels('gemini'))],
            'shopper_provider' => 'sometimes|string|in:openai,deepseek,gemini',
            'marketing_provider' => 'sometimes|string|in:openai,deepseek,gemini',
            'vision_provider' => 'sometimes|string|in:openai,gemini',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->aiConfig->update($validator->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $this->audit->log($request, 'platform.ai_settings.updated', 'platform_setting', null, [
            'provider' => $data['provider'],
            'features' => $data['feature_preferences'] ?? $data['features'] ?? null,
        ]);

        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'AI provider settings updated.',
        ]);
    }

    public function probe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|string|in:openai,deepseek,gemini',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = app(AiChatClient::class)->probe($validator->validated()['provider']);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
