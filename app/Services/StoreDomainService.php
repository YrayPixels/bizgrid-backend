<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreDomain;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StoreDomainService
{
    public function __construct(
        private readonly DnsRecordResolver $dns,
        private readonly MerchantUsageService $usage,
    ) {}

    public function planAllowsCustomDomains(Merchant $merchant): bool
    {
        $plan = $this->usage->planConfig($merchant->subscription_plan ?: 'starter');

        return (bool) ($plan['caps']['custom_domains'] ?? false);
    }

    public function maxCustomDomains(Merchant $merchant): int
    {
        $plan = $this->usage->planConfig($merchant->subscription_plan ?: 'starter');

        return (int) ($plan['caps']['max_custom_domains'] ?? 0);
    }

    public function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = explode('/', $hostname)[0];
        $hostname = explode(':', $hostname)[0];
        $hostname = preg_replace('/^https?:\/\//', '', $hostname) ?? $hostname;

        if (str_starts_with($hostname, 'www.')) {
            $hostname = substr($hostname, 4);
        }

        return $hostname;
    }

    public function validateHostname(string $hostname): void
    {
        if ($hostname === '') {
            throw new InvalidArgumentException('Domain is required.');
        }

        if (strlen($hostname) > 253) {
            throw new InvalidArgumentException('Domain is too long.');
        }

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $hostname)) {
            throw new InvalidArgumentException('Enter a valid domain such as shop.yourbrand.com.');
        }

        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        if ($hostname === $platformDomain || str_ends_with($hostname, '.'.$platformDomain)) {
            throw new InvalidArgumentException('Platform subdomains cannot be added as custom domains.');
        }

        foreach (['localhost', 'vercel.app', 'bizgrid.shop'] as $blocked) {
            if ($hostname === $blocked || str_ends_with($hostname, '.'.$blocked)) {
                throw new InvalidArgumentException('This domain cannot be connected.');
            }
        }
    }

    public function createDomain(Store $store, string $rawHostname): StoreDomain
    {
        $hostname = $this->normalizeHostname($rawHostname);
        $this->validateHostname($hostname);

        if (StoreDomain::where('hostname', $hostname)->exists()) {
            throw new InvalidArgumentException('This domain is already connected to a store.');
        }

        $merchant = $store->merchant;
        if (! $merchant || ! $this->planAllowsCustomDomains($merchant)) {
            throw new InvalidArgumentException('Custom domains are available on Growth and Scale plans.');
        }

        $maxDomains = $this->maxCustomDomains($merchant);
        $currentCount = StoreDomain::where('store_id', $store->id)->count();
        if ($currentCount >= $maxDomains) {
            throw new InvalidArgumentException("Your plan allows up to {$maxDomains} custom domain(s).");
        }

        return StoreDomain::create([
            'store_id' => $store->id,
            'hostname' => $hostname,
            'verification_token' => Str::random(32),
            'status' => 'pending',
            'is_primary' => $currentCount === 0,
        ]);
    }

    public function verifyDomain(StoreDomain $domain, Store $store): StoreDomain
    {
        if ($domain->store_id !== $store->id) {
            throw new InvalidArgumentException('Domain not found.');
        }

        if ($domain->isVerified()) {
            return $domain;
        }

        $store->loadMissing('merchant');
        $checks = $this->runVerificationChecks($domain, $store);

        if (! $checks['verified']) {
            throw new InvalidArgumentException($checks['message']);
        }

        $domain->status = 'verified';
        $domain->verified_at = now();
        $domain->save();

        if ($domain->is_primary) {
            $this->setPrimaryDomain($domain, $store);
        }

        return $domain->fresh();
    }

    /** @return array{verified: bool, message: string, txt_ok: bool, cname_ok: bool} */
    public function verificationStatus(StoreDomain $domain, Store $store): array
    {
        return $this->runVerificationChecks($domain, $store);
    }

    public function setPrimaryDomain(StoreDomain $domain, Store $store): StoreDomain
    {
        if ($domain->store_id !== $store->id) {
            throw new InvalidArgumentException('Domain not found.');
        }

        if (! $domain->isVerified()) {
            throw new InvalidArgumentException('Verify the domain before setting it as primary.');
        }

        StoreDomain::where('store_id', $store->id)
            ->where('id', '!=', $domain->id)
            ->update(['is_primary' => false]);

        $domain->is_primary = true;
        $domain->save();

        return $domain->fresh();
    }

    public function deleteDomain(StoreDomain $domain, Store $store): void
    {
        if ($domain->store_id !== $store->id) {
            throw new InvalidArgumentException('Domain not found.');
        }

        $wasPrimary = $domain->is_primary;
        $domain->delete();

        if ($wasPrimary) {
            StoreDomain::where('store_id', $store->id)
                ->where('status', 'verified')
                ->orderByDesc('verified_at')
                ->limit(1)
                ->update(['is_primary' => true]);
        }
    }

    public function cnameTarget(Store $store): string
    {
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        return "{$store->slug}.{$platformDomain}";
    }

    public function txtHost(string $hostname): string
    {
        return "_storehause-verify.{$hostname}";
    }

    public function formatDomain(StoreDomain $domain, Store $store): array
    {
        $status = $this->verificationStatus($domain, $store);

        return [
            'id' => (string) $domain->id,
            'hostname' => $domain->hostname,
            'status' => $domain->status,
            'is_primary' => $domain->is_primary,
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'verification' => [
                'txt_host' => $this->txtHost($domain->hostname),
                'txt_value' => "storehause-verify={$domain->verification_token}",
                'cname_host' => $domain->hostname,
                'cname_target' => $this->cnameTarget($store),
                'txt_verified' => $status['txt_ok'],
                'cname_verified' => $status['cname_ok'],
            ],
        ];
    }

    /** @return array{verified: bool, message: string, txt_ok: bool, cname_ok: bool} */
    private function runVerificationChecks(StoreDomain $domain, Store $store): array
    {
        $txtOk = $this->hasTxtVerification($domain);
        $cnameOk = $this->hasCnameVerification($domain, $store);

        if ($txtOk && $cnameOk) {
            return [
                'verified' => true,
                'message' => 'Domain verified.',
                'txt_ok' => true,
                'cname_ok' => true,
            ];
        }

        $missing = [];
        if (! $txtOk) {
            $missing[] = 'TXT ownership record';
        }
        if (! $cnameOk) {
            $missing[] = 'CNAME routing record';
        }

        return [
            'verified' => false,
            'message' => 'Waiting for DNS: '.implode(' and ', $missing).'.',
            'txt_ok' => $txtOk,
            'cname_ok' => $cnameOk,
        ];
    }

    private function hasTxtVerification(StoreDomain $domain): bool
    {
        $expected = "storehause-verify={$domain->verification_token}";
        $records = $this->dns->getRecords($this->txtHost($domain->hostname), DNS_TXT);

        foreach ($records as $record) {
            $entries = $record['txt'] ?? $record['entries'] ?? [];
            if (! is_array($entries)) {
                $entries = [$entries];
            }

            foreach ($entries as $entry) {
                if (is_string($entry) && trim($entry) === $expected) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasCnameVerification(StoreDomain $domain, Store $store): bool
    {
        $target = strtolower($this->cnameTarget($store));
        $records = $this->dns->getRecords($domain->hostname, DNS_CNAME);

        foreach ($records as $record) {
            $targetHost = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if ($targetHost === $target) {
                return true;
            }
        }

        return false;
    }
}
