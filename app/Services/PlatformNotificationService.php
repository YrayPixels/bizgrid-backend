<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformNotification;

class PlatformNotificationService
{
    public function notify(string $type, string $title, ?string $body = null, ?array $metadata = null): PlatformNotification
    {
        return PlatformNotification::create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }

    public function unreadCount(): int
    {
        return PlatformNotification::query()->whereNull('read_at')->count();
    }
}
