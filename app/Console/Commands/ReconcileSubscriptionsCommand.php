<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DodoPaymentsService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'storehause:reconcile-subscriptions {--dry-run : Report drift without writing changes}';

    protected $description = 'Sync merchant subscriptions with Dodo Payments, catching state that webhooks never delivered';

    public function handle(DodoPaymentsService $dodoPayments): int
    {
        if (! $dodoPayments->isConfigured()) {
            $this->warn('Dodo Payments is not configured — nothing to reconcile.');

            return self::SUCCESS;
        }

        try {
            $remote = $dodoPayments->fetchRemoteSubscriptions();
        } catch (Throwable $exception) {
            $this->error("Could not fetch subscriptions from Dodo: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Fetched %d subscription(s) from Dodo.', count($remote)));

        $changed = 0;
        $orphaned = 0;

        foreach ($remote as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            if ($this->option('dry-run')) {
                // Reconciling writes, so a dry run can only report what it cannot match.
                // Drift on matched merchants is left to the real run.
                $subscriptionId = $subscription['subscription_id'] ?? '?';
                $this->line("  would reconcile {$subscriptionId} (status: ".($subscription['status'] ?? '?').')');

                continue;
            }

            try {
                $result = $dodoPayments->reconcileSubscription($subscription);
            } catch (Throwable $exception) {
                $subscriptionId = $subscription['subscription_id'] ?? '?';
                $this->error("  {$subscriptionId}: {$exception->getMessage()}");

                continue;
            }

            if ($result !== null) {
                $this->line("  {$result}");
                $changed++;

                continue;
            }

            // A paid subscription we cannot tie to a merchant is the failure mode worth
            // shouting about: someone was charged and got nothing. It happens when a
            // checkout is created outside our flow, so it carries no merchant_id metadata.
            if (($subscription['status'] ?? null) === 'active'
                && ! filled($subscription['metadata']['merchant_id'] ?? null)
            ) {
                $orphaned++;
                $this->warn(sprintf(
                    '  ORPHAN: %s is active but matches no merchant (customer: %s)',
                    $subscription['subscription_id'] ?? '?',
                    $subscription['customer']['customer_id'] ?? '?',
                ));
            }
        }

        $this->info("Reconciled {$changed} merchant(s).");

        if ($orphaned > 0) {
            $this->warn("{$orphaned} active subscription(s) could not be matched to a merchant.");
        }

        return self::SUCCESS;
    }
}
