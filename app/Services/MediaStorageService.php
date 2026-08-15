<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MediaStorageService
{
    public function __construct(
        private readonly GoogleCloudStorageClient $gcs,
        private readonly PlatformGcsConfigService $gcsConfig,
    ) {}

    public function usingCloud(): bool
    {
        return $this->gcsConfig->usingCloud();
    }

    public function store(string $relativePath, string $contents, string $contentType): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($this->usingCloud()) {
            try {
                return $this->gcs->put($this->objectName($relativePath), $contents, $contentType);
            } catch (\Throwable $e) {
                Log::error('GCS upload failed; falling back to local public disk', [
                    'path' => $relativePath,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $full = public_path($relativePath);
        File::ensureDirectoryExists(dirname($full));
        File::put($full, $contents);

        return url($relativePath);
    }

    public function storeUpload(string $directory, UploadedFile $file, string $filename): string
    {
        $contents = $file->getContent();
        if ($contents === '') {
            throw new RuntimeException('Could not read the uploaded file.');
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $directory = trim(str_replace('\\', '/', $directory), '/');

        return $this->store($directory.'/'.$filename, $contents, $mime);
    }

    private function objectName(string $relativePath): string
    {
        $prefix = $this->gcsConfig->pathPrefix();

        return $prefix !== '' ? $prefix.'/'.$relativePath : $relativePath;
    }
}
