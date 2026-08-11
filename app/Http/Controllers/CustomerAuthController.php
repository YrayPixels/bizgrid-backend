<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Store;
use App\Services\CustomerStoreService;
use App\Services\GoogleOAuthService;
use App\Services\StorefrontPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerAuthController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $googleOAuth,
        private readonly CustomerStoreService $customerStores,
        private readonly StorefrontPublishService $publishService,
    ) {}

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        if (! $this->googleOAuth->isConfigured()) {
            abort(503, 'Google sign-in is not configured.');
        }

        $storeSlug = Str::slug((string) $request->query('store_slug', ''));
        $returnUrl = (string) $request->query('return_url', '');

        if ($storeSlug === '') {
            abort(422, 'store_slug is required.');
        }

        $store = Store::query()->where('slug', $storeSlug)->first();
        if (! $store || ! $this->publishService->isPublished($store)) {
            abort(404, 'Storefront not found.');
        }

        if (! $this->isAllowedReturnUrl($returnUrl, $store)) {
            abort(422, 'Invalid return_url.');
        }

        return $this->googleOAuth->redirect('customer', [
            'store_slug' => $store->slug,
            'return_url' => $returnUrl,
        ]);
    }

    public function completeGoogleSignIn(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $payload = $this->googleOAuth->peekState($state) ?? [];
        $returnUrl = is_string($payload['return_url'] ?? null) ? $payload['return_url'] : '';
        $storeSlug = is_string($payload['store_slug'] ?? null) ? $payload['store_slug'] : '';

        $fallbackBase = rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/');
        $errorBase = $returnUrl !== '' ? $this->stripAuthParams($returnUrl) : $fallbackBase.'/';

        $redirectWithError = fn (string $message): RedirectResponse => redirect()->away(
            $this->appendQuery($errorBase, ['customer_auth_error' => $message])
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
            $googleUser = $this->googleOAuth->fetchUser($state);
        } catch (\Throwable $e) {
            Log::warning('Customer Google OAuth callback failed', ['error' => $e->getMessage()]);

            return $redirectWithError('Could not complete Google sign-in. Please try again.');
        }

        $email = strtolower((string) ($googleUser->getEmail() ?? ''));
        if ($email === '') {
            return $redirectWithError('Your Google account does not have an email address we can use.');
        }

        $googleId = (string) $googleUser->getId();
        $customer = Customer::query()->where('google_id', $googleId)->first();

        if (! $customer) {
            $customer = Customer::query()->where('email', $email)->first();

            if ($customer) {
                if (filled($customer->google_id) && $customer->google_id !== $googleId) {
                    return $redirectWithError('This email is already linked to a different Google account.');
                }

                $customer->google_id = $googleId;
                if (! $customer->email_verified_at) {
                    $customer->email_verified_at = now();
                }
                if (filled($googleUser->getName())) {
                    $customer->name = $googleUser->getName();
                }
                $avatar = $googleUser->getAvatar();
                if (is_string($avatar) && $avatar !== '') {
                    $customer->avatar_url = $avatar;
                }
                $customer->save();
            } else {
                $customer = Customer::query()->create([
                    'name' => filled($googleUser->getName())
                        ? $googleUser->getName()
                        : Str::before($email, '@'),
                    'email' => $email,
                    'google_id' => $googleId,
                    'avatar_url' => is_string($googleUser->getAvatar()) ? $googleUser->getAvatar() : null,
                    'email_verified_at' => now(),
                ]);
            }
        }

        if ($storeSlug !== '') {
            $store = Store::query()->where('slug', Str::slug($storeSlug))->first();
            if ($store) {
                $this->customerStores->attach($customer, $store);
            }
        }

        $tokenResult = $customer->createToken('customer');
        $tokenResult->accessToken->expires_at = now()->addDays(30);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        $code = Str::random(64);
        Cache::put("auth:exchange:{$code}", [
            'token' => $token,
            'customer_id' => $customer->id,
            'type' => 'customer',
        ], now()->addMinutes(2));

        $successBase = $returnUrl !== '' ? $this->stripAuthParams($returnUrl) : $fallbackBase.'/';
        $params = ['customer_auth_code' => $code];
        $openTryOn = (bool) ($payload['open_try_on'] ?? false);
        if (! $openTryOn && $returnUrl !== '') {
            $returnParts = parse_url($returnUrl);
            if (is_array($returnParts) && ! empty($returnParts['query'])) {
                parse_str($returnParts['query'], $returnQuery);
                $openTryOn = (($returnQuery['try_on'] ?? null) === '1');
            }
        }
        if ($openTryOn) {
            $params['try_on'] = '1';
        }

        return redirect()->away($this->appendQuery($successBase, $params));
    }

    public function exchangeCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|min:20|max:128',
        ]);

        $cacheKey = "auth:exchange:{$data['code']}";
        $payload = Cache::pull($cacheKey);

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'customer' || empty($payload['token'])) {
            return response()->json(['message' => 'Invalid or expired auth code.'], 401);
        }

        $customer = Customer::query()->find($payload['customer_id'] ?? null);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.'], 401);
        }

        return response()->json([
            'token' => $payload['token'],
            'customer' => $this->formatCustomer($customer),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $storeSlug = Str::slug((string) $request->query('store_slug', ''));
        if ($storeSlug !== '') {
            $store = Store::query()->where('slug', $storeSlug)->first();
            if ($store && $this->publishService->isPublished($store)) {
                $this->customerStores->attach($customer, $store);
            }
        }

        return response()->json([
            'customer' => $this->formatCustomer($customer->fresh() ?? $customer),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatCustomer(Customer $customer): array
    {
        return [
            'id' => (string) $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'avatar_url' => $customer->avatar_url,
            'email_verified_at' => $customer->email_verified_at?->toIso8601String(),
            'stores' => $this->customerStores->storeSummaries($customer),
        ];
    }

    private function isAllowedReturnUrl(string $returnUrl, Store $store): bool
    {
        if ($returnUrl === '' || ! filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($returnUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        $allowed = [];

        $appUrl = (string) config('storehause.app_url', '');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $allowed[] = strtolower($appHost);
        }

        $platformDomain = strtolower((string) config('storehause.platform_domain', ''));
        if ($platformDomain !== '') {
            $allowed[] = $platformDomain;
        }

        if (filled($store->primary_domain)) {
            $allowed[] = strtolower((string) $store->primary_domain);
        }

        foreach ($store->domains()->pluck('hostname') as $hostname) {
            if (is_string($hostname) && $hostname !== '') {
                $allowed[] = strtolower($hostname);
            }
        }

        $allowed[] = 'localhost';
        $allowed[] = '127.0.0.1';

        foreach (array_unique($allowed) as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        // Local / preview hosts configured via env.
        $extra = config('storehause.customer_auth_allowed_hosts', []);
        if (is_array($extra)) {
            foreach ($extra as $allowedHost) {
                if (! is_string($allowedHost) || $allowedHost === '') {
                    continue;
                }
                $allowedHost = strtolower($allowedHost);
                if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function stripAuthParams(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            unset(
                $query['customer_auth_code'],
                $query['customer_auth_error'],
                $query['auth_code'],
                $query['auth_token'],
                $query['auth_error'],
            );
        }

        $rebuilt = $parts['scheme'].'://'.$parts['host'];
        if (! empty($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '/';
        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url.$separator.http_build_query($params);
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ($params as $key => $value) {
            $query[$key] = $value;
        }

        $rebuilt = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost');
        if (! empty($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '/';
        $rebuilt .= '?'.http_build_query($query);
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }
}
