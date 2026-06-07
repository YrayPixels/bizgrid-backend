<?php

namespace App\Http\Controllers;

use App\Support\SuperproxyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ScraperController extends Controller
{
    private const JUMIA_BASE = 'https://www.jumia.com.ng';
    private array $lastJumiaScrapeMeta = [];

    /** Browser-like headers so Jumia does not return 403 to server-side requests. */
    private function jumiaHttp()
    {
        $request = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer' => self::JUMIA_BASE . '/',
        ])->connectTimeout(10)->timeout((int) config('services.jumia.scrape_timeout', 20))->retry(2, 500);

        $proxyUrl = $this->jumiaProxyUrl();
        if ($proxyUrl) {
            $options = SuperproxyResolver::guzzleOptions();
            if (!config('services.jumia.proxy_verify_ssl', true)) {
                $options['verify'] = false;
            }
            $request = $request->withOptions($options);
        }

        return $request;
    }

    private function jumiaProxyUrl(): ?string
    {
        return SuperproxyResolver::httpProxyUrl();
    }

    private function isJumiaBlockedHtml(string $html): bool
    {
        if (stripos($html, 'Performing security verification') !== false) {
            return true;
        }

        if (stripos($html, 'Just a moment') !== false && stripos($html, 'article class="prd') === false) {
            return true;
        }

        return false;
    }

    function searchProducts(Request $request)
    {
        try {
            $item = $request->input('query');
            $page = $request->input('page');
            $discount = $request->input('discount');
            $price_range = $request->input('price_range');
            $brand = $request->input('brand');
            $seller_score = $request->input('seller_score');
            $ratings = $request->input('ratings');
            $limit = $request->input('limit');

            $queries = [
                "item" => $item,
                "page" => $page,
                "discount" => $discount,
                "price_range" => $price_range,
                "brand" => $brand,
                "seller_score" => $seller_score,
                "ratings" => $ratings,
                "limit" => $limit
            ];
            $page_results = $this->getProductListing($queries);

            if ($request->boolean('debug')) {
                return response()->json([
                    'data' => $page_results,
                    'debug' => $this->lastJumiaScrapeMeta,
                ]);
            }

            return response()->json($page_results);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }




    /**
     * Get single product details from a Jumia product page URL.
     * Expects product_link (full URL or path) in query string.
     */
    function getProductDetails(Request $request)
    {
        $product_link = $request->input('product_link');
        if (empty($product_link)) {
            return response()->json(['error' => 'product_link is required'], 422);
        }

        $url = $product_link;
        if (str_starts_with($product_link, '/')) {
            $url = 'https://www.jumia.com.ng' . $product_link;
        } elseif (!preg_match('#^https?://#', $product_link)) {
            $url = 'https://www.jumia.com.ng/' . ltrim($product_link, '/');
        }

        try {
            $response = $this->jumiaHttp()->get($url);
            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch product page'], 502);
            }
            $html = $response->body();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch product page: ' . $e->getMessage()], 502);
        }

        $crawler = new Crawler($html);

        try {
            $name = $crawler->filter('h1.-fs20')->text();
            $name = trim($name);
        } catch (\Exception $e) {
            $name = '';
        }

        $sku = null;
        try {
            $wishlist = $crawler->filter('#wishlist[data-sku]');
            if ($wishlist->count()) {
                $sku = $wishlist->attr('data-sku');
            }
        } catch (\Exception $e) {
        }
        if (!$sku) {
            try {
                $form = $crawler->filter('form#add-to-cart[data-sku]');
                if ($form->count()) {
                    $sku = $form->attr('data-sku');
                }
            } catch (\Exception $e) {
            }
        }

        $brand = null;
        try {
            $brandLink = $crawler->filter('div.-phs div.-pvxs a._more')->first();
            if ($brandLink->count()) {
                $brand = trim($brandLink->text());
            }
        } catch (\Exception $e) {
        }

        $currentPrice = 0;
        $originalPrice = 0;
        try {
            $priceEl = $crawler->filter('span.-b.-ubpt.-tal.-fs24');
            if ($priceEl->count()) {
                preg_match('/₦\s*([\d,]+)/u', $priceEl->text(), $m);
                $currentPrice = isset($m[1]) ? (float) str_replace(',', '', $m[1]) : 0;
            }
        } catch (\Exception $e) {
        }
        try {
            $oldEl = $crawler->filter('span.-gy5.-lthr.-fs16');
            if ($oldEl->count()) {
                preg_match('/₦\s*([\d,]+)/u', $oldEl->text(), $m);
                $originalPrice = isset($m[1]) ? (float) str_replace(',', '', $m[1]) : 0;
            }
        } catch (\Exception $e) {
        }

        $discount = null;
        try {
            $discEl = $crawler->filter('span.bdg._dsct, span[data-disc]');
            if ($discEl->count()) {
                $discount = $discEl->attr('data-disc') ?? trim($discEl->text());
            }
        } catch (\Exception $e) {
        }

        $images = [];
        try {
            $crawler->filter('#imgs img, div.sldr._img._prod img')->each(function ($node) use (&$images) {
                $src = $node->attr('data-src') ?: $node->attr('src');
                if ($src) {
                    $src = str_replace(['150x150', '300x300'], '500x500', $src);
                    $images[] = $src;
                }
            });
        } catch (\Exception $e) {
        }
        $image = $images[0] ?? null;

        $rating = null;
        try {
            $starsEl = $crawler->filter('div.stars._m._al');
            if ($starsEl->count()) {
                $ratingText = $starsEl->text();
                if (preg_match('/(\d+(?:\.\d+)?)\s*out\s*of\s*5/u', $ratingText, $rm)) {
                    $rating = (float) $rm[1];
                }
            }
        } catch (\Exception $e) {
        }

        $shippingText = null;
        try {
            $markup = $crawler->filter('div.markup.-fs12.-pbs');
            if ($markup->count()) {
                $shippingText = trim($markup->text());
            }
        } catch (\Exception $e) {
        }

        $availability = true;
        try {
            $avail = $crawler->filter('p.-df.-i-ctr.-fs12.-pbs.-yl7');
            if ($avail->count()) {
                $availability = true; // e.g. "Few units left" still means available
            }
        } catch (\Exception $e) {
        }

        $product = [
            'name' => $name,
            'sku' => $sku,
            'brand' => $brand,
            'price' => [
                'current' => $currentPrice,
                'original' => $originalPrice,
                'currency' => 'NGN',
                'discount' => $discount,
            ],
            'image' => $image,
            'images' => $images,
            'rating' => $rating,
            'link' => $url,
            'url' => $url,
            'availability' => $availability,
            'shipping' => $shippingText,
        ];

        return response()->json($product);
    }

    /**
     * Catalog/listing scrape (used by searchProducts).
     */
    private function getProductListing(array $queries)
    {
        $params = [];
        if (!empty($queries['item'])) {
            $params['q'] = $queries['item'];
        }
        if (!empty($queries['page'])) {
            $params['page'] = $queries['page'];
        }
        if (!empty($queries['discount'])) {
            $params['discount'] = $queries['discount'];
        }
        if (!empty($queries['price_range'])) {
            $params['price'] = $queries['price_range'];
        }
        if (!empty($queries['seller_score'])) {
            $params['seller_score'] = $queries['seller_score'];
        }
        if (!empty($queries['ratings'])) {
            $params['rating'] = $queries['ratings'];
        }

        if (!empty($queries['brand'])) {
            $path = ltrim((string) $queries['brand'], '/');
            $baseUrl = self::JUMIA_BASE . '/' . $path;
        } else {
            $baseUrl = self::JUMIA_BASE . '/catalog';
        }

        $url = $params ? $baseUrl . '?' . http_build_query($params) : $baseUrl;

        $this->lastJumiaScrapeMeta = [
            'url' => $url,
            'direct_status' => null,
            'direct_body_length' => 0,
            'direct_cards_found' => 0,
            'direct_products_parsed' => 0,
            'proxy_configured' => $this->jumiaProxyUrl() !== null,
            'proxy_source' => SuperproxyResolver::proxySource(),
            'proxy' => SuperproxyResolver::debugInfo(),
        ];

        try {
            $response = $this->jumiaHttp()->get($url);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'response 407')) {
                $this->lastJumiaScrapeMeta['proxy_error'] = 'auth_failed_407';
                Log::warning('Jumia proxy authentication failed', $this->lastJumiaScrapeMeta);

                throw new \RuntimeException(
                    'Proxy authentication failed (407). Check PROXY_URL / PROXY_USERNAME / PROXY_PASSWORD on the backend server and run php artisan config:clear.'
                );
            }

            throw $e;
        }
        $this->lastJumiaScrapeMeta['direct_status'] = $response->status();
        $this->lastJumiaScrapeMeta['direct_body_length'] = strlen($response->body());

        $body = $response->body();

        if (!$response->successful()) {
            Log::warning('Jumia catalog scrape failed', [
                'status' => $response->status(),
                'url' => $url,
                'proxy_configured' => $this->jumiaProxyUrl() !== null,
            ]);

            return [];
        }

        if ($this->isJumiaBlockedHtml($body)) {
            $this->lastJumiaScrapeMeta['blocked'] = true;
            $this->lastJumiaScrapeMeta['hint'] = $this->jumiaProxyUrl() === null
                ? 'Jumia returned a bot-check page. Set PROXY_URL (Superproxy) or JUMIA_PROXY_URL — see docs/SUPERPROXY.md.'
                : 'Jumia returned a bot-check page even through the configured proxy. Try a different proxy IP or zone.';

            Log::warning('Jumia catalog scrape blocked by anti-bot', $this->lastJumiaScrapeMeta);

            return [];
        }

        $crawler = new Crawler($body);
        $productsCards = $crawler->filter('.prd, .prd._fb, article.prd')->each(fn ($node) => $node);
        $this->lastJumiaScrapeMeta['direct_cards_found'] = count($productsCards);
        $limit = !empty($queries['limit']) ? (int) $queries['limit'] : null;
        $cardsProcessed = $limit ? array_slice($productsCards, 0, $limit) : $productsCards;

        $products = [];
        foreach ($cardsProcessed as $node) {
            $parsed = $this->parseListingProductCard($node);
            if ($parsed !== null) {
                $products[] = $parsed;
            }
        }

        $this->lastJumiaScrapeMeta['direct_products_parsed'] = count($products);

        if (count($products) === 0) {
            $this->lastJumiaScrapeMeta['direct_title'] = $crawler->filter('title')->count() > 0
                ? trim($crawler->filter('title')->text())
                : null;

            return [];
        }

        return $products;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseListingProductCard($node): ?array
    {
        $nameEl = $node->filter('h3.name, .name, .info h3');
        $priceEl = $node->filter('.prc, .info .prc');
        $linkEl = $node->filter('a.core, .core');

        if ($nameEl->count() === 0 || $priceEl->count() === 0 || $linkEl->count() === 0) {
            return null;
        }

        $nameElement = trim($nameEl->text());
        $priceElement = $priceEl->text();
        $linkElement = $linkEl->attr('href');
        if ($nameElement === '' || $linkElement === null || $linkElement === '') {
            return null;
        }

        $oldPriceElement = '';
        $oldEl = $node->filter('.s-prc-w .old');
        if ($oldEl->count() > 0) {
            $oldPriceElement = $oldEl->text();
        }

        $images = '';
        $imgEl = $node->filter('img.img');
        if ($imgEl->count() > 0) {
            $images = $imgEl->attr('data-src') ?: $imgEl->attr('src') ?: '';
        }

        $ratingElement = '';
        $ratingEl = $node->filter('.rev .stars, .stars, .rev');
        if ($ratingEl->count() > 0) {
            $ratingElement = $ratingEl->text();
        }

        preg_match('/₦\s*([\d,]+)/u', $priceElement, $priceMatch);
        $currentPrice = isset($priceMatch[1]) ? (float) str_replace(',', '', $priceMatch[1]) : 0;

        preg_match('/₦\s*([\d,]+)/u', $oldPriceElement, $oldPriceMatch);
        $originalPrice = isset($oldPriceMatch[1]) ? (float) str_replace(',', '', $oldPriceMatch[1]) : 0;

        $rating = null;
        if ($ratingElement !== '') {
            preg_match('/(\d+(?:\.\d+)?)\s*out\s*of\s*5/u', $ratingElement, $ratingMatch);
            $rating = isset($ratingMatch[1]) ? (float) $ratingMatch[1] : null;
        }

        $href = ltrim($linkElement, '/');

        return [
            'name' => $nameElement,
            'price' => [
                'current' => $currentPrice,
                'original' => $originalPrice,
                'currency' => 'NGN',
            ],
            'image' => str_replace('300x300', '500x500', $images),
            'rating' => $rating,
            'link' => self::JUMIA_BASE . '/' . $href,
            'availability' => true,
            'url' => $linkElement,
        ];
    }

    function generateProductId($url)
    {
        if (preg_match('/product\/([^\/\?]+)/', $url, $matches)) {
            return $matches[1];
        } else {
            return substr(base64_encode($url), 0, 12);
        }
    }

    function extractCategoryFromUrl($url)
    {
        preg_match('/category\/([^\/\?]+)/', $url, $matches);
        return $matches[1];
    }
}
