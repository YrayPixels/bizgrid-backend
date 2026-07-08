<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PlatformAiConfigService;
use Illuminate\Http\JsonResponse;

class AiConfigController extends Controller
{
    public function __construct(
        private readonly PlatformAiConfigService $aiConfig,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->aiConfig->publicConfig(),
        ]);
    }
}
