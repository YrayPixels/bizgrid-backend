<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Mail\MerchantPasswordResetCodeEmail;
use App\Mail\MerchantWelcomeEmail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use StorehauseHelpers;

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:128',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
        ]);

        try {
            Mail::to($user->email)->send(new MerchantWelcomeEmail($user));
        } catch (\Throwable $e) {
            Log::warning('Failed to send merchant welcome email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = now()->addDays(30);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:128',
            'remember' => 'sometimes|boolean',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $merchant = Merchant::where('owner_user_id', $user->id)->first();
        if ($merchant && $merchant->status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'reason' => $merchant->suspension_reason,
            ], 403);
        }

        $remember = (bool) ($data['remember'] ?? false);
        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = $remember
            ? now()->addDays(30)
            : now()->addDays(1);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $user = User::where('email', strtolower($data['email']))
            ->where('is_admin', false)
            ->first();

        // Do not leak whether the account exists.
        if (! $user) {
            return response()->json([
                'message' => 'If that account exists, a reset code was sent.',
            ]);
        }

        $code = (string) rand(100000, 999999);
        $user->verification_code = Hash::make($code);
        $user->save();

        Mail::to($user->email)->send(new MerchantPasswordResetCodeEmail($user, $code));

        if (config('app.env') === 'local') {
            Log::info('Merchant password reset code', ['email' => $user->email, 'code' => $code]);
        }

        return response()->json([
            'message' => 'If that account exists, a reset code was sent.',
        ]);
    }

    public function resetPasswordWithCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|max:128',
        ]);

        $user = User::where('email', strtolower($data['email']))
            ->where('is_admin', false)
            ->first();

        if (! $user || ! filled($user->verification_code) || ! Hash::check($data['code'], $user->verification_code)) {
            return response()->json(['message' => 'Invalid reset code'], 401);
        }

        $user->password = Hash::make($data['password']);
        $user->verification_code = null;
        $user->save();

        // Optional: revoke existing sessions after password reset.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password updated. You can sign in now.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $merchant = Merchant::where('owner_user_id', $request->user()->id)->first();
        if ($merchant && $merchant->status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'reason' => $merchant->suspension_reason,
            ], 403);
        }

        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }
}
