<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class WorkbenchProjectStorage
{
    private const DISK = 'local';

    public function storageKey(int $sessionId): string
    {
        return "builder-projects/sessions/{$sessionId}";
    }

    public function exists(int $sessionId): bool
    {
        return Storage::disk(self::DISK)->exists($this->storageKey($sessionId).'/manifest.json');
    }

    /**
     * @param  list<array{path?: string, content?: string, encoding?: string|null}>  $customFiles
     * @param  array<string, mixed>|null  $editMetadata
     * @return array{storage_key: string, revision: int, file_count: int, updated_at: string}
     */
    public function save(int $sessionId, array $customFiles, ?array $editMetadata = null): array
    {
        $key = $this->storageKey($sessionId);
        $previousRevision = $this->readManifest($sessionId)['revision'] ?? 0;

        Storage::disk(self::DISK)->deleteDirectory($key);

        $manifestFiles = [];

        foreach ($customFiles as $file) {
            if (! is_array($file)) {
                continue;
            }

            $path = $this->sanitizeRelativePath((string) ($file['path'] ?? ''));
            if ($path === null) {
                continue;
            }

            $encoding = ($file['encoding'] ?? null) === 'base64' ? 'base64' : null;
            $content = (string) ($file['content'] ?? '');
            $diskPath = "{$key}/{$path}";

            if ($encoding === 'base64') {
                $decoded = base64_decode($content, true);
                if ($decoded === false) {
                    continue;
                }

                Storage::disk(self::DISK)->put($diskPath, $decoded);
                $manifestFiles[] = ['path' => $path, 'binary' => true];
            } else {
                Storage::disk(self::DISK)->put($diskPath, $content);
                $manifestFiles[] = ['path' => $path, 'binary' => false];
            }
        }

        $manifest = [
            'revision' => $previousRevision + 1,
            'file_count' => count($manifestFiles),
            'updated_at' => now()->toIso8601String(),
            'locked_paths' => is_array($editMetadata['locked_paths'] ?? null)
                ? array_values($editMetadata['locked_paths'])
                : [],
            'files' => $manifestFiles,
        ];

        Storage::disk(self::DISK)->put(
            "{$key}/manifest.json",
            json_encode($manifest, JSON_THROW_ON_ERROR),
        );

        return $this->pointerFromManifest($key, $manifest);
    }

    /**
     * @return array{
     *     custom_files: list<array{path: string, content: string, encoding?: string}>,
     *     edit_metadata: array{locked_paths: list<string>},
     *     custom_project: array{storage_key: string, revision: int, file_count: int, updated_at: string}
     * }|null
     */
    public function load(int $sessionId): ?array
    {
        $key = $this->storageKey($sessionId);
        $manifest = $this->readManifest($sessionId);

        if ($manifest === null) {
            return null;
        }

        $customFiles = [];

        foreach ($manifest['files'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = $this->sanitizeRelativePath((string) ($entry['path'] ?? ''));
            if ($path === null) {
                continue;
            }

            $diskPath = "{$key}/{$path}";
            if (! Storage::disk(self::DISK)->exists($diskPath)) {
                continue;
            }

            $raw = Storage::disk(self::DISK)->get($diskPath);

            if (! empty($entry['binary'])) {
                $customFiles[] = [
                    'path' => $path,
                    'content' => base64_encode($raw),
                    'encoding' => 'base64',
                ];
            } else {
                $customFiles[] = [
                    'path' => $path,
                    'content' => $raw,
                ];
            }
        }

        return [
            'custom_files' => $customFiles,
            'edit_metadata' => [
                'locked_paths' => is_array($manifest['locked_paths'] ?? null)
                    ? array_values($manifest['locked_paths'])
                    : [],
            ],
            'custom_project' => $this->pointerFromManifest($key, $manifest),
        ];
    }

    /**
     * Move inline snapshot custom_files to disk and strip them from the snapshot payload.
     *
     * @param  array<string, mixed>  $storefront
     */
    public function extractAndPersist(int $sessionId, array &$storefront): void
    {
        $customFiles = $storefront['custom_files'] ?? null;

        if (! is_array($customFiles) || $customFiles === []) {
            return;
        }

        $editMetadata = is_array($storefront['edit_metadata'] ?? null)
            ? $storefront['edit_metadata']
            : null;

        $pointer = $this->save($sessionId, $customFiles, $editMetadata);

        unset($storefront['custom_files'], $storefront['custom_code']);
        $storefront['custom_project'] = $pointer;
    }

    /**
     * Attach on-disk project files to an API-facing storefront snapshot.
     *
     * @param  array<string, mixed>|null  $storefront
     * @return array<string, mixed>|null
     */
    public function hydrateSnapshot(?array $storefront, int $sessionId, bool $migrateInline = true): ?array
    {
        if (! is_array($storefront)) {
            return $storefront;
        }

        if (
            $migrateInline
            && ! empty($storefront['custom_files'])
            && is_array($storefront['custom_files'])
        ) {
            $this->extractAndPersist($sessionId, $storefront);
        }

        $loaded = $this->load($sessionId);

        if ($loaded === null) {
            return $storefront;
        }

        $storefront['custom_files'] = $loaded['custom_files'];
        $storefront['custom_project'] = $loaded['custom_project'];

        if (($loaded['edit_metadata']['locked_paths'] ?? []) !== []) {
            $existing = is_array($storefront['edit_metadata'] ?? null) ? $storefront['edit_metadata'] : [];
            $storefront['edit_metadata'] = array_merge($existing, $loaded['edit_metadata']);
        }

        unset($storefront['custom_code']);

        return $storefront;
    }

    /**
     * @return array{revision?: int, locked_paths?: list<string>, files?: list<array<string, mixed>>}|null
     */
    private function readManifest(int $sessionId): ?array
    {
        $path = $this->storageKey($sessionId).'/manifest.json';

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $decoded = json_decode(Storage::disk(self::DISK)->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function sanitizeRelativePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        if (preg_match('/^[a-z]:/i', $path) === 1) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{storage_key: string, revision: int, file_count: int, updated_at: string}
     */
    private function pointerFromManifest(string $key, array $manifest): array
    {
        return [
            'storage_key' => $key,
            'revision' => (int) ($manifest['revision'] ?? 0),
            'file_count' => (int) ($manifest['file_count'] ?? 0),
            'updated_at' => (string) ($manifest['updated_at'] ?? now()->toIso8601String()),
        ];
    }
}
