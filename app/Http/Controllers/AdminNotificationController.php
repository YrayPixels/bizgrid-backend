<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AdminNotificationController extends Controller
{
    private function normalizePhoneDigits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    private function phoneDigitsMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $len = min(strlen($a), strlen($b), 10);
        if ($len < 7) {
            return false;
        }

        return substr($a, -$len) === substr($b, -$len);
    }

    private function tokensForPhone(string $phone): array
    {
        $target = $this->normalizePhoneDigits($phone);
        if ($target === '') {
            return [];
        }

        return DB::table('push_notification_tokens')
            ->where('is_active', true)
            ->get(['push_token', 'phone_number'])
            ->filter(function ($row) use ($target) {
                return $this->phoneDigitsMatch($target, $this->normalizePhoneDigits($row->phone_number));
            })
            ->pluck('push_token')
            ->unique()
            ->values()
            ->all();
    }

    private function enrichRecipient(object $row): array
    {
        $username = null;
        if ($row->phone_number) {
            $digits = $this->normalizePhoneDigits($row->phone_number);
            $user = DB::table('addressbook')
                ->get(['id', 'username', 'phone_number'])
                ->first(function ($u) use ($digits) {
                    return $this->phoneDigitsMatch($digits, $this->normalizePhoneDigits($u->phone_number));
                });
            if ($user) {
                $username = $user->username;
            }
        }

        return [
            'id' => $row->id,
            'user_id' => $row->user_id,
            'phone_number' => $row->phone_number,
            'username' => $username,
            'device_type' => $row->device_type,
            'is_active' => (bool) $row->is_active,
            'push_token_preview' => strlen($row->push_token) > 28
                ? substr($row->push_token, 0, 22).'…'
                : $row->push_token,
            'last_used_at' => $row->last_used_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function baseTokenQuery(Request $request)
    {
        $query = DB::table('push_notification_tokens');

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        if ($request->filled('device_type') && in_array($request->device_type, ['ios', 'android'], true)) {
            $query->where('device_type', $request->device_type);
        }

        if ($request->filled('phone_number')) {
            $pushTokens = $this->tokensForPhone($request->phone_number);
            $query->whereIn('push_token', ! empty($pushTokens) ? $pushTokens : ['__none__']);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $matchingPhones = DB::table('addressbook')
                ->where('username', 'like', '%'.$search.'%')
                ->orWhere('phone_number', 'like', '%'.$search.'%')
                ->orWhere('wallet_address', 'like', '%'.$search.'%')
                ->pluck('phone_number')
                ->all();

            $query->where(function ($q) use ($search, $matchingPhones) {
                $q->where('phone_number', 'like', '%'.$search.'%')
                    ->orWhere('push_token', 'like', '%'.$search.'%');

                if (! empty($matchingPhones)) {
                    foreach ($matchingPhones as $phone) {
                        $canonical = '+'.$this->normalizePhoneDigits($phone);
                        if ($canonical !== '+') {
                            $q->orWhere('phone_number', $canonical)
                                ->orWhere('phone_number', 'like', '%'.$this->normalizePhoneDigits($phone).'%');
                        }
                    }
                }
            });
        }

        return $query;
    }

    private function resolvePushTokens(Request $request): array
    {
        $target = $request->input('target', 'filtered');

        if ($target === 'all') {
            return DB::table('push_notification_tokens')
                ->where('is_active', true)
                ->pluck('push_token')
                ->unique()
                ->values()
                ->all();
        }

        if ($target === 'selected') {
            $tokens = [];

            if ($request->filled('token_ids')) {
                $ids = array_filter((array) $request->token_ids);
                $fromIds = DB::table('push_notification_tokens')
                    ->whereIn('id', $ids)
                    ->where('is_active', true)
                    ->pluck('push_token')
                    ->all();
                $tokens = array_merge($tokens, $fromIds);
            }

            if ($request->filled('phone_numbers')) {
                foreach ((array) $request->phone_numbers as $phone) {
                    $tokens = array_merge($tokens, $this->tokensForPhone((string) $phone));
                }
            }

            return array_values(array_unique($tokens));
        }

        return $this->baseTokenQuery($request)
            ->pluck('push_token')
            ->unique()
            ->values()
            ->all();
    }

    private function sendToExpo(array $tokens, string $title, string $body, ?array $data): array
    {
        $chunks = array_chunk($tokens, 100);
        $sent = 0;
        $expoResponses = [];

        foreach ($chunks as $chunk) {
            $notifications = [];
            foreach ($chunk as $token) {
                $notifications[] = [
                    'to' => $token,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data ?? ['type' => 'admin_broadcast'],
                ];
            }

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Accept-encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
            ])->post('https://exp.host/--/api/v2/push/send', $notifications);

            if (! $response->successful()) {
                Log::error('Admin push failed: '.$response->body());
                throw new \RuntimeException('Expo push API error: '.$response->body());
            }

            $sent += count($chunk);
            $expoResponses[] = $response->json();
        }

        return ['sent_count' => $sent, 'expo' => $expoResponses];
    }

    /**
     * List registered devices (push tokens) with filters.
     */
    public function listRecipients(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = $this->baseTokenQuery($request)->orderByDesc('updated_at');
        $total = (clone $query)->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $recipients = $rows->map(fn ($row) => $this->enrichRecipient($row))->values();

        $uniquePhones = (clone $this->baseTokenQuery($request))
            ->whereNotNull('phone_number')
            ->distinct()
            ->count('phone_number');

        return response()->json([
            'recipients' => $recipients,
            'meta' => [
                'total' => $total,
                'unique_phones' => $uniquePhones,
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * Preview how many devices would receive a send with current filters.
     */
    public function preview(Request $request)
    {
        $tokens = $this->resolvePushTokens($request);

        $rows = empty($tokens)
            ? collect()
            : DB::table('push_notification_tokens')->whereIn('push_token', $tokens)->get();

        $ios = $rows->where('device_type', 'ios')->count();
        $android = $rows->where('device_type', 'android')->count();
        $phones = $rows->pluck('phone_number')->filter()->unique()->count();

        return response()->json([
            'device_count' => count($tokens),
            'user_count' => $phones,
            'ios_count' => $ios,
            'android_count' => $android,
        ]);
    }

    /**
     * Send a custom push notification to filtered or selected users/devices.
     */
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:1000',
            'data' => 'nullable|array',
            'target' => 'nullable|in:all,filtered,selected',
            'device_type' => 'nullable|in:ios,android',
            'search' => 'nullable|string|max:200',
            'phone_number' => 'nullable|string',
            'phone_numbers' => 'nullable|array',
            'phone_numbers.*' => 'string',
            'token_ids' => 'nullable|array',
            'token_ids.*' => 'integer',
            'active_only' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tokens = $this->resolvePushTokens($request);

            if (empty($tokens)) {
                return response()->json([
                    'message' => 'No devices match the current filters',
                ], 404);
            }

            $result = $this->sendToExpo(
                $tokens,
                $request->title,
                $request->body,
                $request->data
            );

            return response()->json([
                'message' => 'Notifications sent successfully',
                'sent_count' => $result['sent_count'],
                'device_count' => count($tokens),
                'response' => $result['expo'],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin notification send failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to send notifications',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
