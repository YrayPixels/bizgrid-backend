<?php

namespace App\Http\Controllers;

use App\Agents\ProductDescriptionAgent;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductAiController extends Controller
{
    public function describe(Request $request, ProductDescriptionAgent $agent): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        @ini_set('max_execution_time', '120');

        $data = $request->validate([
            'name' => 'required|string|max:200',
            'category' => 'nullable|string|max:120',
            'price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'image_url' => 'nullable|string|url|max:2048',
            'existing_description' => 'nullable|string|max:2000',
            'style' => 'nullable|string|max:80',
        ]);

        if (! $agent->available()) {
            return response()->json([
                'error' => 'AI API key is not configured.',
            ], 503);
        }

        /** @var Store|null $store */
        $store = Store::query()
            ->with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->first();

        try {
            $result = $agent->execute([
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'price' => $data['price'] ?? null,
                'currency' => $data['currency'] ?? 'NGN',
                'image_url' => $data['image_url'] ?? null,
                'existing_description' => $data['existing_description'] ?? null,
                'style' => $data['style'] ?? null,
                'business_name' => $store?->name,
                'industry' => $store?->merchant?->industry,
                'store_description' => $store?->description,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Product description generation failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Could not generate a product description. Try again.',
            ], 502);
        }

        if (! $result || empty($result['description'])) {
            return response()->json([
                'error' => 'Could not generate a product description. Try again.',
            ], 422);
        }

        return response()->json([
            'description' => $result['description'],
            'source' => $result['source'] ?? 'copy',
        ]);
    }
}
