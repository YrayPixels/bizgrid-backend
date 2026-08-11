<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformVisitController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => 'nullable|string|max:120',
            'path' => 'nullable|string|max:2048',
            'referrer' => 'nullable|string|max:2048',
            'utm_source' => 'nullable|string|max:80',
            'utm_medium' => 'nullable|string|max:80',
            'utm_campaign' => 'nullable|string|max:120',
            'utm_content' => 'nullable|string|max:120',
        ]);

        PlatformVisit::create([
            'session_id' => $data['session_id'] ?? null,
            'path' => $data['path'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'visited_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Visit recorded.',
        ], 201);
    }
}
