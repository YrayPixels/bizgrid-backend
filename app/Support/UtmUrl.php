<?php

namespace App\Support;

/**
 * Merge last-touch marketing UTMs onto outbound storefront URLs so visits
 * and orders can be attributed back to posts, ads, or recovery outreach.
 */
class UtmUrl
{
    /**
     * @param  array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_content?: string}  $params
     */
    public static function merge(string $url, array $params): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $key) {
            if (! filled($params[$key] ?? null)) {
                continue;
            }
            $query[$key] = (string) $params[$key];
        }

        $rebuilt = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string}
     */
    public static function forSocialPost(string $provider, int $postId, string $campaignSlug): array
    {
        $source = match ($provider) {
            'instagram' => 'instagram',
            'tiktok_creator', 'tiktok' => 'tiktok',
            default => 'facebook',
        };

        return [
            'utm_source' => $source,
            'utm_medium' => 'social',
            'utm_campaign' => self::slug($campaignSlug),
            'utm_content' => 'post_'.$postId,
        ];
    }

    /**
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string}
     */
    public static function forAdCampaign(int $campaignId, string $campaignName): array
    {
        return [
            'utm_source' => 'meta_ads',
            'utm_medium' => 'paid',
            'utm_campaign' => self::slug($campaignName !== '' ? $campaignName : 'ad-'.$campaignId),
            'utm_content' => 'ad_'.$campaignId,
        ];
    }

    /**
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string}
     */
    public static function forRecovery(string $channel, string $campaignSlug): array
    {
        $medium = $channel === 'whatsapp' ? 'whatsapp' : 'email';

        return [
            'utm_source' => 'recovery',
            'utm_medium' => $medium,
            'utm_campaign' => self::slug($campaignSlug),
            'utm_content' => 'recovery',
        ];
    }

    public static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 80) : 'store';
    }
}
