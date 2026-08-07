<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreAudienceSnapshot;
use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Log;

/**
 * Audience demographics for the KPI dashboard: who follows this store, by age,
 * gender and country.
 *
 * Meta suppresses these metrics entirely for Pages and IG accounts under 100
 * followers, so an empty result is a normal state for a new merchant — not an
 * error — and the UI is told so explicitly.
 */
class AudienceInsightsService
{
    public const MIN_AUDIENCE_FOR_DEMOGRAPHICS = 100;

    /** Buckets Meta reports, in display order. */
    private const AGE_BUCKETS = ['13-17', '18-24', '25-34', '35-44', '45-54', '55-64', '65+'];

    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

    /**
     * Refresh demographics for every connected Meta channel on the store.
     *
     * @return array{captured: int, suppressed: int}
     */
    public function refreshForStore(Store $store): array
    {
        $captured = 0;
        $suppressed = 0;

        $facebookPages = $store->socialConnections()->where('provider', 'facebook')->get();

        foreach ($facebookPages as $page) {
            $snapshot = $this->captureFacebookPage($store, $page);
            $snapshot === null ? $suppressed++ : $captured++;
        }

        $instagram = $store->socialConnections()->where('provider', InstagramService::PROVIDER)->latest()->first();

        if ($instagram instanceof StoreSocialConnection) {
            $snapshot = $this->captureInstagram($store, $instagram);
            $snapshot === null ? $suppressed++ : $captured++;
        }

        return ['captured' => $captured, 'suppressed' => $suppressed];
    }

    /**
     * Latest snapshot per channel, merged into one view for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function summaryForStore(Store $store): array
    {
        $snapshots = StoreAudienceSnapshot::query()
            ->where('store_id', $store->id)
            ->orderByDesc('captured_at')
            ->get()
            // One row per provider — the most recent capture wins.
            ->unique('provider')
            ->values();

        if ($snapshots->isEmpty()) {
            return $this->emptySummary();
        }

        $ageTotals = [];
        $countryTotals = [];
        $totalAudience = 0;

        foreach ($snapshots as $snapshot) {
            $totalAudience += (int) $snapshot->total_audience;

            foreach ((array) $snapshot->age_gender as $row) {
                if (! is_array($row) || ! isset($row['bucket'])) {
                    continue;
                }

                $bucket = (string) $row['bucket'];
                $ageTotals[$bucket] ??= ['bucket' => $bucket, 'male' => 0.0, 'female' => 0.0];
                $ageTotals[$bucket]['male'] += (float) ($row['male'] ?? 0);
                $ageTotals[$bucket]['female'] += (float) ($row['female'] ?? 0);
            }

            foreach ((array) $snapshot->countries as $row) {
                if (! is_array($row) || ! isset($row['code'])) {
                    continue;
                }

                $code = (string) $row['code'];
                $countryTotals[$code] ??= [
                    'code' => $code,
                    'name' => (string) ($row['name'] ?? $code),
                    'count' => 0,
                ];
                $countryTotals[$code]['count'] += (int) ($row['count'] ?? 0);
            }
        }

        // Percentages have to be recomputed after merging channels, otherwise
        // two channels' percentages would sum past 100.
        $ageRows = array_values($ageTotals);
        $ageSum = array_sum(array_map(fn (array $r): float => $r['male'] + $r['female'], $ageRows));

        if ($ageSum > 0) {
            $ageRows = array_map(function (array $row) use ($ageSum): array {
                $row['male'] = round(($row['male'] / $ageSum) * 100, 1);
                $row['female'] = round(($row['female'] / $ageSum) * 100, 1);
                $row['total'] = round($row['male'] + $row['female'], 1);

                return $row;
            }, $ageRows);
        }

        usort($ageRows, fn (array $a, array $b): int => array_search($a['bucket'], self::AGE_BUCKETS, true)
            <=> array_search($b['bucket'], self::AGE_BUCKETS, true));

        $countries = array_values($countryTotals);
        usort($countries, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $countries = array_slice($countries, 0, 6);

        $countrySum = array_sum(array_column($countries, 'count'));
        $countries = array_map(function (array $row) use ($countrySum): array {
            $row['share'] = $countrySum > 0 ? round(($row['count'] / $countrySum) * 100, 1) : 0.0;

            return $row;
        }, $countries);

        $topBucket = $ageRows === [] ? null : collect($ageRows)->sortByDesc('total')->first();

        return [
            'available' => $ageRows !== [] || $countries !== [],
            'suppressed_reason' => $ageRows === [] && $countries === []
                ? 'Meta only shares audience demographics once a channel passes '.self::MIN_AUDIENCE_FOR_DEMOGRAPHICS.' followers.'
                : null,
            'total_audience' => $totalAudience,
            'top_age_bucket' => $topBucket['bucket'] ?? null,
            'age_gender' => $ageRows,
            'countries' => $countries,
            'top_country' => $countries[0] ?? null,
            'captured_at' => $snapshots->first()?->captured_at?->toIso8601String(),
            'channels' => $snapshots->pluck('provider')->values()->all(),
        ];
    }

    private function captureFacebookPage(Store $store, StoreSocialConnection $page): ?StoreAudienceSnapshot
    {
        $token = (string) $page->page_access_token;

        try {
            $response = $this->graph->get("/{$page->page_id}/insights", [
                'metric' => 'page_fans_gender_age,page_fans_country',
                'period' => 'lifetime',
            ], $token);
        } catch (\Throwable $e) {
            Log::info('Facebook audience insights unavailable', [
                'page_id' => $page->page_id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $ageGender = [];
        $countries = [];

        foreach ($response['data'] ?? [] as $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $values = $metric['values'][0]['value'] ?? [];
            if (! is_array($values) || $values === []) {
                continue;
            }

            if (($metric['name'] ?? '') === 'page_fans_gender_age') {
                $ageGender = $this->normalizeGenderAge($values);
            }

            if (($metric['name'] ?? '') === 'page_fans_country') {
                $countries = $this->normalizeCountries($values);
            }
        }

        if ($ageGender === [] && $countries === []) {
            return null;
        }

        return $this->store($store, $page, 'facebook', $ageGender, $countries);
    }

    private function captureInstagram(Store $store, StoreSocialConnection $connection): ?StoreAudienceSnapshot
    {
        $token = (string) $connection->page_access_token;

        try {
            // IG moved demographics to the demographic breakdown API; the
            // follower_demographics metric needs a breakdown parameter.
            $ageResponse = $this->graph->get("/{$connection->page_id}/insights", [
                'metric' => 'follower_demographics',
                'period' => 'lifetime',
                'metric_type' => 'total_value',
                'breakdown' => 'age,gender',
            ], $token);

            $countryResponse = $this->graph->get("/{$connection->page_id}/insights", [
                'metric' => 'follower_demographics',
                'period' => 'lifetime',
                'metric_type' => 'total_value',
                'breakdown' => 'country',
            ], $token);
        } catch (\Throwable $e) {
            Log::info('Instagram audience insights unavailable', [
                'ig_id' => $connection->page_id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $ageGender = $this->normalizeGenderAge($this->flattenBreakdown($ageResponse));
        $countries = $this->normalizeCountries($this->flattenBreakdown($countryResponse));

        if ($ageGender === [] && $countries === []) {
            return null;
        }

        return $this->store($store, $connection, InstagramService::PROVIDER, $ageGender, $countries);
    }

    /**
     * IG returns breakdown results as nested dimension/value pairs; flatten
     * them into the flat "F.25-34" => n shape the Page metric already uses.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, int>
     */
    private function flattenBreakdown(array $response): array
    {
        $flat = [];
        $results = $response['data'][0]['total_value']['breakdowns'][0]['results'] ?? [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $dimensions = $result['dimension_values'] ?? [];
            $value = (int) ($result['value'] ?? 0);

            if (! is_array($dimensions) || $dimensions === []) {
                continue;
            }

            // age+gender comes back as ["25-34", "F"]; country as ["NG"].
            $key = count($dimensions) === 2
                ? $dimensions[1].'.'.$dimensions[0]
                : (string) $dimensions[0];

            $flat[$key] = ($flat[$key] ?? 0) + $value;
        }

        return $flat;
    }

    /**
     * Meta keys gender/age as "M.25-34" / "F.25-34" / "U.25-34".
     *
     * @param  array<string, int>  $values
     * @return list<array{bucket: string, male: float, female: float, total: float}>
     */
    private function normalizeGenderAge(array $values): array
    {
        $buckets = [];
        $total = array_sum($values);

        if ($total <= 0) {
            return [];
        }

        foreach ($values as $key => $count) {
            if (! is_string($key) || ! str_contains($key, '.')) {
                continue;
            }

            [$gender, $bucket] = explode('.', $key, 2);

            if (! in_array($bucket, self::AGE_BUCKETS, true)) {
                continue;
            }

            $buckets[$bucket] ??= ['bucket' => $bucket, 'male' => 0.0, 'female' => 0.0];

            // "U" (undeclared) is dropped rather than guessed at — it would
            // otherwise silently inflate one gender.
            if (strtoupper($gender) === 'M') {
                $buckets[$bucket]['male'] += (float) $count;
            } elseif (strtoupper($gender) === 'F') {
                $buckets[$bucket]['female'] += (float) $count;
            }
        }

        return array_values(array_map(function (array $row) use ($total): array {
            $row['male'] = round(($row['male'] / $total) * 100, 1);
            $row['female'] = round(($row['female'] / $total) * 100, 1);
            $row['total'] = round($row['male'] + $row['female'], 1);

            return $row;
        }, $buckets));
    }

    /**
     * @param  array<string, int>  $values
     * @return list<array{code: string, name: string, count: int}>
     */
    private function normalizeCountries(array $values): array
    {
        $rows = [];

        foreach ($values as $code => $count) {
            if (! is_string($code) || strlen($code) !== 2) {
                continue;
            }

            $rows[] = [
                'code' => strtoupper($code),
                'name' => $this->countryName(strtoupper($code)),
                'count' => (int) $count,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, 12);
    }

    private function countryName(string $code): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'en');

            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /**
     * @param  list<array<string, mixed>>  $ageGender
     * @param  list<array<string, mixed>>  $countries
     */
    private function store(
        Store $store,
        StoreSocialConnection $connection,
        string $provider,
        array $ageGender,
        array $countries,
    ): StoreAudienceSnapshot {
        return StoreAudienceSnapshot::create([
            'store_id' => $store->id,
            'social_connection_id' => $connection->id,
            'provider' => $provider,
            'age_gender' => $ageGender,
            'countries' => $countries,
            'total_audience' => array_sum(array_column($countries, 'count')),
            'captured_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'available' => false,
            'suppressed_reason' => 'Connect a Facebook Page or Instagram account to see who your audience is.',
            'total_audience' => 0,
            'top_age_bucket' => null,
            'age_gender' => [],
            'countries' => [],
            'top_country' => null,
            'captured_at' => null,
            'channels' => [],
        ];
    }
}
