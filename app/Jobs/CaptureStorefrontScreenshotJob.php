<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Store;
use App\Services\StorefrontScreenshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CaptureStorefrontScreenshotJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $storeId) {}

    public function handle(StorefrontScreenshotService $screenshots): void
    {
        $store = Store::query()->find($this->storeId);
        if (! $store) {
            return;
        }

        $screenshots->captureAndStore($store);
    }
}
