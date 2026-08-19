<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CaptureStorefrontScreenshotJob;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class StorefrontScreenshotService
{
    public function __construct(
        private readonly MediaStorageService $media,
    ) {}

    public function enabled(): bool
    {
        $driver = $this->driver();

        return (bool) config('storehause.storefront_screenshots.enabled', true)
            && $driver !== 'none';
    }

    public function browserUrl(?Store $store): ?string
    {
        if (! $store || ! filled($store->preview_screenshot_url)) {
            return null;
        }

        $url = $this->media->browserUrl((string) $store->preview_screenshot_url) ?: $store->preview_screenshot_url;

        return filled($url) && str_starts_with((string) $url, 'https://') ? (string) $url : null;
    }

    public function queueCapture(Store $store): void
    {
        if (! $this->enabled() || ! $this->isPublished($store)) {
            return;
        }

        CaptureStorefrontScreenshotJob::dispatch($store->id);
    }

    public function captureAndStore(Store $store): ?string
    {
        if (! $this->enabled() || ! $this->isPublished($store)) {
            return null;
        }

        $url = $this->publicStorefrontUrl($store);

        try {
            $contents = $this->captureImageBytes($url);
            if ($contents === '') {
                return null;
            }

            $stored = $this->media->store(
                'storehause/storefront-previews/'.$store->id.'/'.Str::uuid().'.jpg',
                $contents,
                'image/jpeg',
            );

            $store->preview_screenshot_url = $stored;
            $store->preview_screenshot_at = now();
            $store->save();

            return $this->browserUrl($store);
        } catch (\Throwable $e) {
            Log::warning('Storefront screenshot capture failed.', [
                'store_id' => $store->id,
                'url' => $url,
                'driver' => $this->driver(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function publicStorefrontUrl(Store $store): string
    {
        $platformDomain = strtolower(trim((string) config('storehause.platform_domain', 'bizgrid.shop')));
        $appUrl = rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/');

        if (
            str_ends_with($platformDomain, '.vercel.app')
            || in_array($platformDomain, ['localhost', '127.0.0.1'], true)
            || app()->environment('local', 'testing')
        ) {
            return $appUrl.'/s/'.$store->slug;
        }

        return 'https://'.$store->slug.'.'.$platformDomain;
    }

    private function driver(): string
    {
        $driver = strtolower(trim((string) config('storehause.storefront_screenshots.driver', 'http')));

        return in_array($driver, ['http', 'puppeteer', 'none'], true) ? $driver : 'http';
    }

    private function captureImageBytes(string $pageUrl): string
    {
        return match ($this->driver()) {
            'puppeteer' => $this->captureViaPuppeteer($pageUrl),
            'http' => $this->captureViaHttp($pageUrl),
            default => throw new RuntimeException('Screenshot driver is disabled.'),
        };
    }

    private function captureViaHttp(string $pageUrl): string
    {
        $customTemplate = trim((string) config('storehause.storefront_screenshots.http_api_url', ''));
        if ($customTemplate !== '') {
            return $this->downloadFromTemplate($customTemplate, $pageUrl);
        }

        $width = (int) config('storehause.storefront_screenshots.width', 390);
        $height = (int) config('storehause.storefront_screenshots.height', 720);
        $timeout = (int) config('storehause.storefront_screenshots.timeout_seconds', 120);

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders(array_filter([
                'x-api-key' => config('storehause.storefront_screenshots.microlink_api_key'),
            ]))
            ->get('https://api.microlink.io/', [
                'url' => $pageUrl,
                'screenshot' => 'true',
                'meta' => 'false',
                'embed' => 'screenshot.url',
                'viewport.width' => $width,
                'viewport.height' => $height,
                'viewport.deviceScaleFactor' => 2,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Screenshot API request failed.');
        }

        $imageUrl = data_get($response->json(), 'data.screenshot.url');
        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new RuntimeException('Screenshot API did not return an image URL.');
        }

        return $this->downloadImage($imageUrl, $timeout);
    }

    private function downloadFromTemplate(string $template, string $pageUrl): string
    {
        $endpoint = str_replace(
            ['{url}', '{width}', '{height}'],
            [rawurlencode($pageUrl), (string) config('storehause.storefront_screenshots.width', 390), (string) config('storehause.storefront_screenshots.height', 720)],
            $template,
        );

        return $this->downloadImage($endpoint, (int) config('storehause.storefront_screenshots.timeout_seconds', 120));
    }

    private function downloadImage(string $url, int $timeout): string
    {
        $response = Http::timeout($timeout)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Failed to download screenshot image.');
        }

        $body = $response->body();
        if ($body === '') {
            throw new RuntimeException('Screenshot image was empty.');
        }

        return $body;
    }

    private function captureViaPuppeteer(string $pageUrl): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'storefront-preview-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temp file.');
        }

        $output = $tmp.'.jpg';

        try {
            $this->runPuppeteerCapture($pageUrl, $output);
            $contents = file_get_contents($output);

            return is_string($contents) ? $contents : '';
        } finally {
            @unlink($tmp);
            @unlink($output);
        }
    }

    private function runPuppeteerCapture(string $url, string $output): void
    {
        $script = base_path('scripts/capture-storefront-screenshot.mjs');
        if (! is_file($script)) {
            throw new RuntimeException('Screenshot script is missing.');
        }

        $node = (string) config('storehause.storefront_screenshots.node_binary', 'node');
        $width = (string) config('storehause.storefront_screenshots.width', 390);
        $height = (string) config('storehause.storefront_screenshots.height', 720);

        $process = new Process([
            $node,
            $script,
            '--url',
            $url,
            '--output',
            $output,
            '--width',
            $width,
            '--height',
            $height,
        ], base_path(), null, null, (int) config('storehause.storefront_screenshots.timeout_seconds', 120));

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        if (! is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('Screenshot file was not created.');
        }
    }

    private function isPublished(Store $store): bool
    {
        return $store->status === 'published'
            && is_array($store->published_json)
            && $store->published_json !== [];
    }
}
