<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Mail\MerchantPasswordResetCodeEmail;
use App\Mail\MerchantWelcomeEmail;
use App\Models\Merchant;
use App\Models\MerchantStaff;
use App\Models\User;
use App\Services\GoogleOAuthService;
use App\Services\MerchantEmailVerificationService;
use App\Services\MerchantMembershipService;
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
        private readonly MerchantMembershipService $membership,
        private readonly MerchantEmailVerificationService $emailVerification,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

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

        $verificationSent = $this->emailVerification->sendCode($user);

        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = now()->addDays(30);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user->fresh()),
            'email_verification_sent' => $verificationSent,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! filled($user->password) || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $staff = null;
        $merchant = Merchant::where('owner_user_id', $user->id)->first();
        if (! $merchant) {
            $staff = MerchantStaff::query()
                ->with('merchant')
                ->where('user_id', $user->id)
                ->first();
            if ($staff && $staff->status !== MerchantStaff::STATUS_ACTIVE) {
                return response()->json([
                    'message' => 'Your staff account is disabled. Contact your store owner.',
                ], 403);
            }
            $merchant = $staff?->merchant;
        } else {
            // Heal staff accounts that accidentally got their own pending merchant.
            $this->membership->discardOrphanOwnerMerchantForStaff($user);
            $staff = MerchantStaff::query()
                ->with('merchant')
                ->where('user_id', $user->id)
                ->where('status', MerchantStaff::STATUS_ACTIVE)
                ->first();
            if ($staff) {
                $merchant = $staff->merchant;
            }
        }
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
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function redirectToGoogle(): RedirectResponse
    {
        if (! $this->googleOAuth->isConfigured()) {
            abort(503, 'Google sign-in is not configured.');
        }

        return $this->googleOAuth->redirect('merchant');
    }

    public function handleGoogleCallback(
        Request $request,
        AdminController $adminController,
        CustomerAuthController $customerAuthController,
    ): RedirectResponse {
        $state = (string) $request->query('state', '');
        $intent = $this->googleOAuth->peekIntent($state) ?? 'merchant';

        if ($intent === 'admin') {
            return $adminController->completeGoogleSignIn($request, $this->googleOAuth);
        }

        if ($intent === 'customer') {
            return $customerAuthController->completeGoogleSignIn($request);
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

        $staff = MerchantStaff::query()
            ->with('merchant')
            ->where('user_id', $user->id)
            ->first();

        if ($staff && $staff->status !== MerchantStaff::STATUS_ACTIVE) {
            return $redirectWithError('Your staff account is disabled. Contact your store owner.');
        }

        // Active staff should join the employer store — never create their own merchant.
        if ($staff && $staff->status === MerchantStaff::STATUS_ACTIVE) {
            $this->membership->discardOrphanOwnerMerchantForStaff($user);
            $merchant = $staff->merchant;
        } else {
            $merchant = Merchant::ensurePendingForUser($user);
        }

        $this->invalidateAdminApiCache();
        if ($merchant && $merchant->status === 'suspended') {
            return $redirectWithError('Your account has been suspended. Please contact support.');
        }

        $token = $this->issueMerchantToken($user, 30);

        // Exchange code pattern: generate short-lived code instead of embedding token in URL
        $code = Str::random(64);
        \Illuminate\Support\Facades\Cache::put("auth:exchange:{$code}", [
            'token' => $token,
            'user_id' => $user->id,
            'type' => 'merchant',
        ], now()->addMinutes(2));

        return redirect()->away($frontendBase.'/login?auth_code='.urlencode($code));
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

        $code = (string) random_int(100000, 999999);
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
                'email_verification_sent' => true,
            ]);
        }

        $sent = $this->emailVerification->sendCode($user);

        if (! $sent) {
            return response()->json([
                'message' => 'We could not send the verification email. Please try again in a moment or contact support.',
                'code' => 'mail_send_failed',
                'email_verification_sent' => false,
            ], 503);
        }

        return response()->json([
            'message' => 'Verification code sent.',
            'email_verification_sent' => true,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $membership = app(\App\Services\MerchantMembershipService::class)->membershipFor($request->user());
        if ($membership && $membership['merchant']->status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'reason' => $membership['merchant']->suspension_reason,
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

    public function exchangeCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|size:64',
        ]);

        $payload = \Illuminate\Support\Facades\Cache::pull("auth:exchange:{$data['code']}");

        if (! is_array($payload) || ! isset($payload['token'], $payload['user_id'])) {
            return response()->json([
                'error' => 'Invalid or expired code.',
            ], 401);
        }

        $user = User::find($payload['user_id']);
        if (! $user) {
            return response()->json([
                'error' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'token' => $payload['token'],
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * One-click demo merchant session for organizers/judges.
     * Enabled only when STOREHAUSE_DEMO_LOGIN=true and DemoMerchantSeeder has run.
     */
    public function demoLogin(Request $request): JsonResponse|RedirectResponse
    {
        if (! config('storehause.demo_login')) {
            if ($request->expectsJson() || $request->isMethod('POST')) {
                return response()->json(['message' => 'Demo login is disabled.'], 404);
            }

            $frontendBase = rtrim((string) config('storehause.app_url'), '/');

            return redirect()->away($frontendBase.'/demo?error='.urlencode('Demo login is disabled.'));
        }

        $email = strtolower((string) config('storehause.demo_email', 'demo@bizgrid.shop'));
        $user = User::query()
            ->where('email', $email)
            ->where('is_admin', false)
            ->first();

        if (! $user) {
            if ($request->expectsJson() || $request->isMethod('POST')) {
                return response()->json([
                    'message' => 'Demo account is not seeded. Run: php artisan db:seed --class=DemoMerchantSeeder',
                ], 503);
            }

            $frontendBase = rtrim((string) config('storehause.app_url'), '/');

            return redirect()->away($frontendBase.'/demo?error='.urlencode('Demo account is not seeded.'));
        }

        $merchant = Merchant::query()->where('owner_user_id', $user->id)->first();
        if ($merchant && $merchant->status === 'suspended') {
            if ($request->expectsJson() || $request->isMethod('POST')) {
                return response()->json([
                    'message' => 'Demo account has been suspended.',
                ], 403);
            }

            $frontendBase = rtrim((string) config('storehause.app_url'), '/');

            return redirect()->away($frontendBase.'/demo?error='.urlencode('Demo account has been suspended.'));
        }

        // Limit token pile-up from repeated demo clicks.
        $user->tokens()->where('name', 'demo-login')->delete();

        $tokenResult = $user->createToken('demo-login');
        $tokenResult->accessToken->expires_at = now()->addDays(1);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        if ($request->expectsJson() || $request->isMethod('POST')) {
            return response()->json([
                'token' => $token,
                'user' => $this->formatUser($user),
            ]);
        }

        $code = Str::random(64);
        \Illuminate\Support\Facades\Cache::put("auth:exchange:{$code}", [
            'token' => $token,
            'user_id' => $user->id,
            'type' => 'merchant',
        ], now()->addMinutes(2));

        $frontendBase = rtrim((string) config('storehause.app_url'), '/');

        return redirect()->away($frontendBase.'/login?auth_code='.urlencode($code));
    }

    private function issueMerchantToken(User $user, int $days): string
    {
        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = now()->addDays($days);
        $tokenResult->accessToken->save();

        return $tokenResult->plainTextToken;
    }
}
