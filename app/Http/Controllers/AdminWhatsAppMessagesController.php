<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WhatsAppMerchantMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhatsAppMessagesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WhatsAppMerchantMessage::query()
            ->with([
                'session:id,phone,state,user_id',
                'session.user:id,name,email',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('direction') && in_array($request->direction, ['inbound', 'outbound'], true)) {
            $query->where('direction', $request->direction);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $digits = preg_replace('/\D+/', '', $search) ?? '';
            $query->where(function ($q) use ($search, $digits): void {
                $q->where('body', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        $perPage = min((int) $request->get('per_page', 30), 100);
        $messages = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $messages->getCollection()->map(fn ($message) => $this->formatMessage($message)),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    private function formatMessage(WhatsAppMerchantMessage $message): array
    {
        $session = $message->session;
        $user = $session?->user;

        return [
            'id' => $message->id,
            'phone' => $message->phone,
            'direction' => $message->direction,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'provider_message_id' => $message->provider_message_id,
            'metadata' => $message->metadata ?? [],
            'profile_name' => $message->metadata['profile_name'] ?? null,
            'profile_username' => $message->metadata['profile_username'] ?? null,
            'session_state' => $session?->state,
            'merchant_name' => $user?->name,
            'merchant_email' => $user?->email,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
