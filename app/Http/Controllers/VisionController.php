<?php

namespace App\Http\Controllers;

use App\Agents\VisionAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisionController extends Controller
{
    /**
     * Analyze a product image and return extracted details.
     * Called by the frontend's process_product_image tool.
     */
    public function analyzeProduct(Request $request, VisionAgent $vision): JsonResponse
    {
        $data = $request->validate([
            'image_url' => 'required|string|url',
            'business_name' => 'nullable|string|max:200',
            'industry' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:2000',
        ]);

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
