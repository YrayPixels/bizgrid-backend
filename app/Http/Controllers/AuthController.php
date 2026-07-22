<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Mail\MerchantEmailVerificationCodeEmail;
use App\Mail\MerchantPasswordResetCodeEmail;
use App\Mail\MerchantWelcomeEmail;
use App\Models\Merchant;
use App\Models\User;
use App\Services\GoogleOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly GoogleOAuthService $googleOAuth,
    ) {}

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

        Merchant::ensurePendingForUser($user);
        $this->invalidateAdminApiCache();

        try {
            Mail::to($user->email)->send(new MerchantWelcomeEmail($user));
        } catch (\Throwable $e) {
            Log::warning('Failed to send merchant welcome email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendEmailVerificationCode($user);

        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = now()->addDays(30);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user->fresh()),
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

        if (! $user || ! filled($user->password) || ! Hash::check($data['password'], $user->password)) {
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
        $token = $this->issueMerchantToken($user, $remember ? 30 : 1);

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function redirectToGoogle(): RedirectResponse
    {
        if (! $this->googleOAuth->isConfigured()) {
            abort(503, 'Google sign-in is not configured.');
        }

        return $this->googleOAuth->redirect('merchant');
    }

    public function handleGoogleCallback(Request $request, AdminController $adminController): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $intent = $this->googleOAuth->consumeIntent($state) ?? 'merchant';

        if ($intent === 'admin') {
            return $adminController->completeGoogleSignIn($request, $this->googleOAuth);
        }

        return $this->completeMerchantGoogleSignIn($request);
    }

    private function completeMerchantGoogleSignIn(Request $request): RedirectResponse
    {
        $frontendBase = rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/');
        $redirectWithError = fn (string $message): RedirectResponse => redirect()->away(
            $frontendBase.'/login?auth_error='.urlencode($message)
        );

        if ($request->filled('error')) {
            return $redirectWithError(
                (string) $request->query('error_description', 'Google sign-in was cancelled.')
            );
        }

        if (! $this->googleOAuth->isConfigured()) {
            return $redirectWithError('Google sign-in is not configured.');
        }

        try {
            $googleUser = $this->googleOAuth->fetchUser((string) $request->query('state', ''));
        } catch (\Throwable $e) {
            Log::warning('Google OAuth callback failed', ['error' => $e->getMessage()]);

            return $redirectWithError('Could not complete Google sign-in. Please try again.');
        }

        $email = strtolower((string) ($googleUser->getEmail() ?? ''));
        if ($email === '') {
            return $redirectWithError('Your Google account does not have an email address we can use.');
        }

        $googleId = (string) $googleUser->getId();
        $user = User::where('google_id', $googleId)->first();

        if (! $user) {
            $user = User::where('email', $email)->first();

            if ($user) {
                if (filled($user->google_id) && $user->google_id !== $googleId) {
                    return $redirectWithError('This email is already linked to a different Google account.');
                }

                $user->google_id = $googleId;
                if (! $user->email_verified_at) {
                    $user->email_verified_at = now();
                }
                $user->save();
            } else {
                $user = User::create([
                    'name' => filled($googleUser->getName())
                        ? $googleUser->getName()
                        : Str::before($email, '@'),
                    'email' => $email,
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                ]);

                try {
                    Mail::to($user->email)->send(new MerchantWelcomeEmail($user));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send merchant welcome email after Google sign-in', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $merchant = Merchant::ensurePendingForUser($user);
        $this->invalidateAdminApiCache();
        if ($merchant->status === 'suspended') {
            return $redirectWithError('Your account has been suspended. Please contact support.');
        }

        $token = $this->issueMerchantToken($user, 30);

        return redirect()->away($frontendBase.'/login?auth_token='.urlencode($token));
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
        $user->verification_code_expires_at = now()->addMinutes(15);
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

        if (
            ! $user
            || ! filled($user->verification_code)
            || ! $user->verification_code_expires_at
            || $user->verification_code_expires_at->isPast()
            || ! Hash::check($data['code'], $user->verification_code)
        ) {
            return response()->json(['message' => 'Invalid reset code'], 401);
        }

        $user->password = Hash::make($data['password']);
        $user->verification_code = null;
        $user->verification_code_expires_at = null;
        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        // Optional: revoke existing sessions after password reset.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password updated. You can sign in now.',
        ]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if ($user->email_verified_at) {
            $this->invalidateUserApiCache((int) $user->id);

            return response()->json([
                'message' => 'Email already verified.',
                'user' => $this->formatUser($user),
            ]);
        }

        if (
            ! filled($user->verification_code)
            || ! $user->verification_code_expires_at
            || $user->verification_code_expires_at->isPast()
            || ! Hash::check($data['code'], $user->verification_code)
        ) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 422);
        }

        $user->email_verified_at = now();
        $user->verification_code = null;
        $user->verification_code_expires_at = null;
        $user->save();

        $this->invalidateUserApiCache((int) $user->id);

        return response()->json([
            'message' => 'Email verified.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function resendEmailVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            $this->invalidateUserApiCache((int) $user->id);

            return response()->json([
                'message' => 'Email already verified.',
                'user' => $this->formatUser($user),
            ]);
        }

        $this->sendEmailVerificationCode($user);

        return response()->json([
            'message' => 'Verification code sent.',
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
            'user' => $this->formatUser(
                $request->user(),
                $request->user()->currentAccessToken()?->name === 'admin-impersonation'
            ),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }

    private function issueMerchantToken(User $user, int $days): string
    {
        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = now()->addDays($days);
        $tokenResult->accessToken->save();

        return $tokenResult->plainTextToken;
    }

    private function sendEmailVerificationCode(User $user): void
    {
        $code = (string) rand(100000, 999999);
        $user->verification_code = Hash::make($code);
        $user->verification_code_expires_at = now()->addMinutes(15);
        $user->save();

        try {
            Mail::to($user->email)->send(new MerchantEmailVerificationCodeEmail($user, $code));
        } catch (\Throwable $e) {
            Log::warning('Failed to send merchant email verification code', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        if (config('app.env') === 'local') {
            Log::info('Merchant email verification code', ['email' => $user->email, 'code' => $code]);
        }
    }
}
