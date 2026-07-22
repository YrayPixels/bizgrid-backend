<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OrderLifecycleService;
use Illuminate\Console\Command;

class ReleaseUnpaidOrdersCommand extends Command
{
    protected $signature = 'storehause:release-unpaid-orders {--hours=24 : Hours after placement before releasing stock}';

    protected $description = 'Cancel unpaid awaiting_payment orders and restore inventory';

    public function handle(OrderLifecycleService $lifecycle): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $released = $lifecycle->releaseExpiredUnpaidOrders($hours);

        $this->info("Released {$released} unpaid order(s).");

        return self::SUCCESS;
    }
}
