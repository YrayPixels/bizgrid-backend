<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    private function normalizePhoneDigits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    /** Store as +{digits} so iOS/Android rows for the same user match on send. */
    private function canonicalPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = $this->normalizePhoneDigits($phone);
        if ($digits === '') {
            return null;
        }

        return '+'.$digits;
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

    /** All active device tokens for a phone (iOS + Android, multiple installs). */
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
                $rowDigits = $this->normalizePhoneDigits($row->phone_number);

                return $this->phoneDigitsMatch($target, $rowDigits);
            })
            ->pluck('push_token')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Register a push notification token
     */
    public function registerToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'push_token' => 'required|string',
            'device_type' => 'required|in:ios,android',
            'phone_number' => 'nullable|string',
            'user_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $canonicalPhone = $this->canonicalPhone($request->phone_number);

            // Check if token already exists
            $existingToken = DB::table('push_notification_tokens')
                ->where('push_token', $request->push_token)
                ->first();

            if ($existingToken) {
                DB::table('push_notification_tokens')
                    ->where('id', $existingToken->id)
                    ->update([
                        'phone_number' => $canonicalPhone,
                        'user_id' => $request->user_id,
                        'device_type' => $request->device_type,
                        'is_active' => true,
                        'last_used_at' => now(),
                        'updated_at' => now()
                    ]);

                $tokenId = $existingToken->id;
            } else {
                $tokenId = DB::table('push_notification_tokens')->insertGetId([
                    'user_id' => $request->user_id,
                    'phone_number' => $canonicalPhone,
                    'push_token' => $request->push_token,
                    'device_type' => $request->device_type,
                    'is_active' => true,
                    'last_used_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // One active token per platform per phone; keep iOS + Android separate.
            if ($canonicalPhone) {
                DB::table('push_notification_tokens')
                    ->where('phone_number', $canonicalPhone)
                    ->where('device_type', $request->device_type)
                    ->where('push_token', '!=', $request->push_token)
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'message' => $existingToken ? 'Token updated successfully' : 'Token registered successfully',
                'token_id' => $tokenId
            ], $existingToken ? 200 : 201);
        } catch (\Exception $e) {
            Log::error('Token registration failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Token registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a push notification
     */
    public function sendNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string', // Can be a specific token or 'all'
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:1000',
            'data' => 'nullable|array',
            'phone_number' => 'nullable|string',
            'user_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $tokens = [];

            // Target user devices first (all iOS + Android tokens for this account).
            if ($request->filled('phone_number')) {
                $tokens = $this->tokensForPhone($request->phone_number);
            } elseif ($request->filled('user_id')) {
                $tokens = DB::table('push_notification_tokens')
                    ->where('user_id', $request->user_id)
                    ->where('is_active', true)
                    ->pluck('push_token')
                    ->unique()
                    ->values()
                    ->all();
            } elseif ($request->to === 'all') {
                $tokens = DB::table('push_notification_tokens')
                    ->where('is_active', true)
                    ->pluck('push_token')
                    ->unique()
                    ->values()
                    ->all();
            } else {
                $tokens = [$request->to];
            }

            if (empty($tokens)) {
                return response()->json([
                    'message' => 'No valid tokens found'
                ], 404);
            }

            $notifications = [];
            foreach ($tokens as $token) {
                $notifications[] = [
                    'to' => $token,
                    'title' => $request->title,
                    'body' => $request->body,
                    'data' => $request->data ?? []
                ];
            }

            // Send notifications via Expo Push API
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Accept-encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
            ])->post('https://exp.host/--/api/v2/push/send', $notifications);

            if ($response->successful()) {
                return response()->json([
                    'message' => 'Notifications sent successfully',
                    'sent_count' => count($tokens),
                    'response' => $response->json()
                ], 200);
            } else {
                Log::error('Expo push notification failed: ' . $response->body());
                return response()->json([
                    'message' => 'Failed to send notifications',
                    'error' => $response->body()
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Notification sending failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Notification sending failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all tokens for a user
     */
    public function getUserTokens(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'nullable|string',
            'user_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = DB::table('push_notification_tokens')
                ->where('is_active', true);

            if ($request->phone_number) {
                $pushTokens = $this->tokensForPhone($request->phone_number);
                $tokens = empty($pushTokens)
                    ? collect()
                    : DB::table('push_notification_tokens')
                        ->whereIn('push_token', $pushTokens)
                        ->get();
            } elseif ($request->user_id) {
                $tokens = $query->where('user_id', $request->user_id)->get();
            } else {
                $tokens = $query->get();
            }

            return response()->json([
                'message' => 'Tokens retrieved successfully',
                'tokens' => $tokens
            ], 200);
        } catch (\Exception $e) {
            Log::error('Token retrieval failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Token retrieval failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate a token
     */
    public function deactivateToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'push_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updated = DB::table('push_notification_tokens')
                ->where('push_token', $request->push_token)
                ->update([
                    'is_active' => false,
                    'updated_at' => now()
                ]);

            if ($updated) {
                return response()->json([
                    'message' => 'Token deactivated successfully'
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Token not found'
                ], 404);
            }
        } catch (\Exception $e) {
            Log::error('Token deactivation failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Token deactivation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a test notification
     */
    public function sendTestNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'nullable|string',
            'user_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        if (! $request->filled('phone_number') && ! $request->filled('user_id')) {
            return response()->json([
                'message' => 'phone_number or user_id is required for a targeted test',
            ], 422);
        }

        $testRequest = new Request([
            'to' => 'phone',
            'title' => 'Test Notification',
            'body' => 'This is a test notification from HeySolana!',
            'data' => ['type' => 'test'],
            'phone_number' => $request->phone_number,
            'user_id' => $request->user_id,
        ]);

        return $this->sendNotification($testRequest);
    }
}
