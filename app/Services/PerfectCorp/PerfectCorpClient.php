<?php

namespace App\Services\PerfectCorp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PerfectCorpClient
{
    public function isStub(): bool
    {
        return (bool) config('perfectcorp.stub', true);
    }

    public function isConfigured(): bool
    {
        if ($this->isStub()) {
            return true;
        }

        return filled(config('perfectcorp.api_key'));
    }

    /**
     * Upload image bytes to PerfectCorp File API and return file_id.
     * Required for localhost / private URLs — PerfectCorp cannot download them.
     */
    public function uploadImageBytes(string $bytes, string $contentType, string $fileName): string
    {
        if ($this->isStub()) {
            return 'stub_file_'.Str::uuid()->toString();
        }

        $size = strlen($bytes);
        if ($size < 100) {
            throw new RuntimeException('Image is too small to upload.');
        }
        if ($size > 10 * 1024 * 1024) {
            throw new RuntimeException('Image must be under 10MB.');
        }

        $response = $this->http()->post('/s2s/v2.0/file', [
            'files' => [[
                'content_type' => $contentType,
                'file_name' => $fileName,
                'file_size' => $size,
            ]],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp file create failed: '.$response->body());
        }

        $file = data_get($response->json(), 'data.files.0');
        $fileId = data_get($file, 'file_id');
        $putUrl = data_get($file, 'requests.0.url');
        $putHeaders = data_get($file, 'requests.0.headers', []);

        if (! is_string($fileId) || $fileId === '' || ! is_string($putUrl) || $putUrl === '') {
            throw new RuntimeException('PerfectCorp file create returned an incomplete upload target.');
        }

        $put = Http::withHeaders(is_array($putHeaders) ? $putHeaders : [])
            ->withBody($bytes, $contentType)
            ->timeout(60)
            ->put($putUrl);

        if (! $put->successful()) {
            throw new RuntimeException('PerfectCorp file upload failed: '.$put->status().' '.$put->body());
        }

        return $fileId;
    }

    /**
     * Fetch image content from a local public path or remote URL for upload.
     *
     * @return array{bytes: string, content_type: string, file_name: string}
     */
    public function resolveImageForUpload(string $url): array
    {
        $localPath = $this->localPathFromAppUrl($url);
        if ($localPath !== null && is_file($localPath)) {
            $bytes = (string) file_get_contents($localPath);
            $mime = mime_content_type($localPath) ?: 'image/jpeg';
            $ext = pathinfo($localPath, PATHINFO_EXTENSION) ?: 'jpg';

            return [
                'bytes' => $bytes,
                'content_type' => $this->normalizeContentType($mime),
                'file_name' => 'upload_'.Str::uuid()->toString().'.'.$ext,
            ];
        }

        $response = Http::timeout(30)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Could not download image for try-on upload.');
        }

        $bytes = $response->body();
        $mime = $response->header('Content-Type') ?: 'image/jpeg';
        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'heic') => 'heic',
            default => 'jpg',
        };

        return [
            'bytes' => $bytes,
            'content_type' => $this->normalizeContentType($mime),
            'file_name' => 'upload_'.Str::uuid()->toString().'.'.$ext,
        ];
    }

    public function uploadFromUrl(string $url): string
    {
        $image = $this->resolveImageForUpload($url);

        return $this->uploadImageBytes($image['bytes'], $image['content_type'], $image['file_name']);
    }

    /**
     * @return array{task_id: string}
     */
    public function createBagTask(string $srcFileId, string $refFileId, string $gender, string $style): array
    {
        return $this->createTask('bag', '/s2s/v2.0/task/bag', [
            'src_file_id' => $srcFileId,
            'ref_file_id' => $refFileId,
            'gender' => $gender,
            'style' => $style,
        ]);
    }

    /**
     * @return array{task_id: string}
     */
    public function createClothTask(string $srcFileId, string $refFileId, string $garmentCategory): array
    {
        return $this->createTask('cloth', '/s2s/v2.0/task/cloth-v4', [
            'src_file_id' => $srcFileId,
            'ref_file_id' => $refFileId,
            'garment_category' => $garmentCategory,
        ]);
    }

    /**
     * @return array{task_id: string}
     */
    public function createHatTask(string $srcFileId, string $refFileId, string $gender, string $style): array
    {
        return $this->createTask('hat', '/s2s/v2.0/task/hat', [
            'src_file_id' => $srcFileId,
            'ref_file_id' => $refFileId,
            'gender' => $gender,
            'style' => $style,
        ]);
    }

    /**
     * @return array{task_id: string}
     */
    public function createShoesTask(string $srcFileId, string $refFileId, string $gender, string $style): array
    {
        return $this->createTask('shoes', '/s2s/v2.0/task/shoes', [
            'src_file_id' => $srcFileId,
            'ref_file_id' => $refFileId,
            'gender' => $gender,
            'style' => $style,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{task_id: string}
     */
    public function createNailTask(array $payload): array
    {
        return $this->createTask('nail', '/s2s/v2.0/task/nail-vto', $payload);
    }

    /**
     * @return array{task_id: string}
     */
    public function createWatchTask(string $srcFileId, string $refFileId): array
    {
        return $this->createTask('watch', '/s2s/v2.0/task/2d-vto/watch', [
            'src_file_id' => $srcFileId,
            'ref_file_ids' => [$refFileId],
            'watch_shadow_intensity' => 0.15,
            'watch_ambient_light_intensity' => 1.0,
        ]);
    }

    /**
     * @return array{task_id: string}
     */
    public function createNecklaceTask(string $srcFileId, string $refFileId): array
    {
        return $this->createTask('necklace', '/s2s/v2.0/task/2d-vto/necklace', [
            'src_file_id' => $srcFileId,
            'ref_file_ids' => [$refFileId],
            'necklace_shadow_intensity' => 0.15,
            'necklace_ambient_light_intensity' => 1.0,
        ]);
    }

    /**
     * @return array{task_id: string}
     */
    public function createFabricTask(string $srcFileId, string $templateId): array
    {
        return $this->createTask('fabric', '/s2s/v2.0/task/fabric', [
            'src_file_id' => $srcFileId,
            'template_id' => $templateId,
        ]);
    }

    /**
     * @return array{task_status: string, result_url: ?string, error: mixed}
     */
    public function getTaskStatus(string $mode, string $taskId): array
    {
        $path = match ($mode) {
            'clothes' => '/s2s/v2.0/task/cloth-v4/'.$taskId,
            'hat' => '/s2s/v2.0/task/hat/'.$taskId,
            'shoes' => '/s2s/v2.0/task/shoes/'.$taskId,
            'nail' => '/s2s/v2.0/task/nail-vto/'.$taskId,
            'watch' => '/s2s/v2.0/task/2d-vto/watch/'.$taskId,
            'necklace' => '/s2s/v2.0/task/2d-vto/necklace/'.$taskId,
            'fabric' => '/s2s/v2.0/task/fabric/'.$taskId,
            default => '/s2s/v2.0/task/bag/'.$taskId,
        };

        return $this->getTask($mode, $path, $taskId);
    }

    public function getBagTask(string $taskId): array
    {
        return $this->getTaskStatus('bag', $taskId);
    }

    public function getClothTask(string $taskId): array
    {
        return $this->getTaskStatus('clothes', $taskId);
    }

    /**
     * @return list<array{id: string, name: string, thumbnail_url: ?string}>
     */
    public function listFabricTemplates(): array
    {
        if ($this->isStub()) {
            return [
                ['id' => 'stub_silk_001', 'name' => 'Silk', 'thumbnail_url' => null],
                ['id' => 'stub_linen_001', 'name' => 'Linen', 'thumbnail_url' => null],
                ['id' => 'stub_cotton_001', 'name' => 'Cotton', 'thumbnail_url' => null],
                ['id' => 'stub_satin_001', 'name' => 'Satin', 'thumbnail_url' => null],
                ['id' => 'stub_denim_001', 'name' => 'Denim', 'thumbnail_url' => null],
            ];
        }

        $response = $this->http()->get('/s2s/v2.0/task/template/fabric', [
            'page_size' => 50,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp fabric templates failed: '.$response->body());
        }

        $json = $response->json();
        $raw = data_get($json, 'data.templates')
            ?? data_get($json, 'data.items')
            ?? data_get($json, 'data.list')
            ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $templates = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? $item['template_id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $name = $item['name'] ?? $item['title'] ?? $id;
            $thumb = $item['thumbnail_url'] ?? $item['thumbnail'] ?? $item['image_url'] ?? null;
            $templates[] = [
                'id' => $id,
                'name' => is_string($name) && $name !== '' ? $name : $id,
                'thumbnail_url' => is_string($thumb) && $thumb !== '' ? $thumb : null,
            ];
        }

        return $templates;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{task_id: string}
     */
    private function createTask(string $label, string $path, array $payload): array
    {
        if ($this->isStub()) {
            return ['task_id' => 'stub_'.$label.'_'.Str::uuid()->toString()];
        }

        $response = $this->http()->post($path, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp '.$label.' task failed: '.$response->body());
        }

        $taskId = data_get($response->json(), 'data.task_id');
        if (! is_string($taskId) || $taskId === '') {
            throw new RuntimeException('PerfectCorp '.$label.' task returned no task_id.');
        }

        return ['task_id' => $taskId];
    }

    /**
     * @return array{task_status: string, result_url: ?string, error: mixed}
     */
    private function getTask(string $label, string $path, string $taskId): array
    {
        if ($this->isStub() || str_starts_with($taskId, 'stub_')) {
            return [
                'task_status' => 'success',
                'result_url' => null,
                'error' => null,
            ];
        }

        $response = $this->http()->get($path);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp '.$label.' status failed: '.$response->body());
        }

        $json = $response->json();

        return [
            'task_status' => (string) data_get($json, 'data.task_status', 'unknown'),
            'result_url' => $this->extractResultUrl($json),
            'error' => data_get($json, 'data.error'),
        ];
    }

    private function extractResultUrl(mixed $json): ?string
    {
        $candidates = [
            data_get($json, 'data.results.url'),
            data_get($json, 'data.results.0.url'),
            data_get($json, 'data.results.urls.0'),
            data_get($json, 'data.result.url'),
            data_get($json, 'data.files.0.url'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $results = data_get($json, 'data.results');
        if (is_array($results) && isset($results[0]) && is_string($results[0]) && $results[0] !== '') {
            return $results[0];
        }

        return null;
    }

    private function http(): PendingRequest
    {
        $apiKey = (string) config('perfectcorp.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('PerfectCorp API key is not configured.');
        }

        return Http::baseUrl((string) config('perfectcorp.base_url'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(60);
    }

    private function normalizeContentType(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return match ($mime) {
            'image/jpg', 'image/jpeg' => 'image/jpg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            'image/heic' => 'image/heic',
            default => 'image/jpg',
        };
    }

    private function localPathFromAppUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        // http://localhost:8000/storehause/try-on/{id}/file.jpg → public/storehause/...
        if (str_starts_with($path, '/storehause/')) {
            $candidate = public_path(ltrim($path, '/'));
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && str_starts_with($url, $appUrl.'/')) {
            $relative = substr($url, strlen($appUrl) + 1);
            $candidate = public_path($relative);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
