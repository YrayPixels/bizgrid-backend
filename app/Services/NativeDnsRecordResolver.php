<?php

declare(strict_types=1);

namespace App\Services;

class NativeDnsRecordResolver implements DnsRecordResolver
{
    public function getRecords(string $hostname, int $type): array
    {
        $records = @dns_get_record($hostname, $type);

        return is_array($records) ? $records : [];
    }
}
