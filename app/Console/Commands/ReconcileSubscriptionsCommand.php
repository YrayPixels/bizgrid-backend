<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PaystackBillingService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'storehause:reconcile-subscriptions {--dry-run : Report drift without writing changes}';

    protected $description = 'Sync merchant subscriptions with Paystack, catching state that webhooks never delivered';

    public function handle(PaystackBillingService $billing): int
    {
        if (! $billing->isConfigured()) {
            $this->warn('Paystack billing is not configured — nothing to reconcile.');

            return self::SUCCESS;
        }

        try {
            $remote = $billing->fetchRemoteSubscriptions();
        } catch (Throwable $exception) {
            $this->error("Could not fetch subscriptions from Paystack: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Fetched %d subscription(s) from Paystack.', count($remote)));

        $changed = 0;
        $orphaned = 0;

        foreach ($remote as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $subscriptionId = $subscription['subscription_code'] ?? '?';
                $this->line('  would reconcile '.$subscriptionId.' (status: '.($subscription['status'] ?? '?').')');

                continue;
            }

            try {
                $result = $billing->reconcileSubscription($subscription);
            } catch (Throwable $exception) {
                $subscriptionId = $subscription['subscription_code'] ?? '?';
                $this->error("  {$subscriptionId}: {$exception->getMessage()}");

                continue;
            }

            if ($result !== null) {
                $this->line("  {$result}");
                $changed++;

                continue;
            }

            $status = strtolower((string) ($subscription['status'] ?? ''));
            $metadata = is_array($subscription['metadata'] ?? null) ? $subscription['metadata'] : [];
            if (
                $status === 'active'
                && ! filled($metadata['merchant_id'] ?? null)
            ) {
                $orphaned++;
                $this->warn(sprintf(
                    '  ORPHAN: %s is active but matches no merchant (customer: %s)',
                    $subscription['subscription_code'] ?? '?',
                    data_get($subscription, 'customer.customer_code') ?? '?',
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
