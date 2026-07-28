<?php

namespace App\Http\Controllers;

use App\Agents\VisionAgent;
use App\Services\MerchantUsageEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisionController extends Controller
{
    public function __construct(
        private readonly MerchantUsageEnforcementService $enforcement,
    ) {}

    /**
     * Analyze a product image and return extracted details.
     * Called by the frontend's process_product_image tool.
     */
    public function analyzeProduct(Request $request, VisionAgent $vision): JsonResponse
    {
        $data = $request->validate([
            'image_url' => 'required|string|max:10240',
            'business_name' => 'nullable|string|max:200',
            'industry' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:2000',
        ]);

        // Validate image_url: must be http(s) URL or data:image/*
        $imageUrl = $data['image_url'];
        if (
            ! str_starts_with($imageUrl, 'http://') &&
            ! str_starts_with($imageUrl, 'https://') &&
            ! str_starts_with($imageUrl, 'data:image/')
        ) {
            return response()->json([
                'error' => 'Invalid image_url. Must be an HTTP(S) URL or data:image/* URL.',
            ], 422);
        }

        // Enforce AI plan limits + consume credit
        $merchant = $this->enforcement->merchantForUser((int) $request->user()->id);
        if ($merchant) {
            $this->enforcement->assertCanUseAi($merchant);
            $this->enforcement->consumeAiCredit($merchant);
        }

        $result = $vision->analyzeProductImage($data['image_url'], [
            'business_name' => $data['business_name'] ?? null,
            'industry' => $data['industry'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        if (isset($result['error'])) {
            return response()->json([
                'error' => 'Could not analyze the image.',
                'detail' => $result['error'],
            ], 422);
        }

        if (! $result) {
            return response()->json([
                'error' => 'Could not analyze the image. Try uploading a clearer product photo.',
            ], 422);
        }

        return response()->json(['product' => $result]);
    }
}
