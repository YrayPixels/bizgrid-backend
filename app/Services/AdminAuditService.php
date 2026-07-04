<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AdminAuditService
{
    public function log(
        Request $request,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $metadata = null,
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'admin_user_id' => $request->user()?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
