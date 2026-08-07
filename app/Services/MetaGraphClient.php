<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Meta Graph API shared by the Facebook, Instagram and
 * Ads services. Keeps the version, error unwrapping and nested-parameter
 * encoding in one place instead of three near-identical private copies.
 */
class MetaGraphClient
{
    public function version(): string
    {
        return (string) config('facebook.graph_version', 'v21.0');
    }

    public function url(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function get(string $path, array $payload = [], ?string $accessToken = null): array
    {
        return $this->request('get', $path, $payload, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], ?string $accessToken = null): array
    {
        return $this->request('post', $path, $payload, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function delete(string $path, array $payload = [], ?string $accessToken = null): array
    {
        return $this->request('delete', $path, $payload, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $payload = [], ?string $accessToken = null, string $fallbackError = 'Facebook API request failed.'): array
    {
        $url = $this->url($path);
        $request = Http::acceptJson()->timeout(30);

        if ($accessToken !== null) {
            $payload['access_token'] = $accessToken;
        }

        $payload = $this->encodeNestedParameters($payload);

        $response = match (strtolower($method)) {
            'get' => $request->get($url, $payload),
            'post' => $request->post($url, $payload),
            'delete' => $request->delete($url, $payload),
            default => throw new RuntimeException("Unsupported Graph API method [{$method}]."),
        };

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $fallbackError));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Graph expects complex parameters (targeting specs, link_data, …) as
     * JSON-encoded strings rather than PHP-style bracketed arrays.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function encodeNestedParameters(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $payload[$key] = $value ? 'true' : 'false';
            }
        }

        return $payload;
    }

    public function errorMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $error = $payload['error'] ?? null;

        if (! is_array($error)) {
            return $fallback;
        }

        // error_user_msg is the human-readable variant Meta returns for ads
        // policy and budget rejections — much better than the generic message.
        foreach (['error_user_msg', 'message'] as $key) {
            if (isset($error[$key]) && is_string($error[$key]) && $error[$key] !== '') {
                return $error[$key];
            }
        }

        return $fallback;
    }
}
