<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformEventController extends Controller
{
    public const ALLOWED_EVENTS = [
        'preview_started',
        'preview_ready',
        'claim_store_clicked',
        'preview_signup_completed',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:80', Rule::in(self::ALLOWED_EVENTS)],
            'session_id' => 'nullable|string|max:120',
            'source' => 'nullable|string|max:80',
            'utm_source' => 'nullable|string|max:80',
            'utm_medium' => 'nullable|string|max:80',
            'utm_campaign' => 'nullable|string|max:120',
            'utm_content' => 'nullable|string|max:120',
        ]);

        PlatformEvent::create([
            'session_id' => $data['session_id'] ?? null,
            'event' => $data['event'],
            'source' => $data['source'] ?? null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event recorded.',
        ], 201);
    }
}
