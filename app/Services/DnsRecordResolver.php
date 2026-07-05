<?php

declare(strict_types=1);

namespace App\Services;

interface DnsRecordResolver
{
    /** @return list<array<string, mixed>> */
    public function getRecords(string $hostname, int $type): array;
}
