# Superproxy Implementation

Residential Superproxy-compatible endpoints (e.g. Bright Data `brd.superproxy.io`) are configured via environment variables. There is no local proxy server in this repository.

## Laravel (Jumia catalog scraper)

The HeySolana backend Jumia endpoints (`POST /api/jumia/scrape/search`, `POST /api/jumia/scrape/product-details`) use **Laravel Http + DomCrawler** and read the same proxy env vars via `App\Support\SuperproxyResolver`:

1. `JUMIA_PROXY_URL` or `OUTBOUND_PROXY_URL` (full URL, optional override)
2. Else `PROXY_URL=host:port:username:password`
3. Else `PROXY_HOST` + `PROXY_PORT` + `PROXY_USERNAME` + `PROXY_PASSWORD`

Example for production `.env`:

```env
PROXY_URL=brd.superproxy.io:33335:brd-customer-xxx-zone-residential:your-password
JUMIA_PROXY_VERIFY_SSL=false
```

Then `php artisan config:clear` and test:

```bash
curl -X POST 'https://your-api/api/jumia/scrape/search?debug=1' \
  -H 'Content-Type: application/json' \
  -d '{"query":"iphone","limit":3}'
```

Look for `debug.proxy_source: "superproxy_env"` and `debug.direct_cards_found` > 0.

---

## Node / Puppeteer scraper (reference)

The following sections describe a separate Node scraper that uses Puppeteer. The env variable names above are shared with Laravel.

## Configuration

Proxy configuration is read from environment variables when the browser manager singleton is first created.

Preferred single-variable format:

```env
PROXY_URL=host:port:username:password
```

Equivalent split-variable format:

```env
PROXY_HOST=host
PROXY_PORT=port
PROXY_USERNAME=username
PROXY_PASSWORD=password
```

For Bright Data/Superproxy, the host is typically `brd.superproxy.io` and the port is typically `33335`. Keep real usernames and passwords in `.env` or your deployment provider's secrets, not in committed documentation.

## Code Flow

The implementation lives mainly in `src/services/browser-manager.ts` and `src/scrapers/browser-scraper.ts`.

1. `dotenv.config()` runs in `src/index.ts`, making proxy environment variables available to the process.
2. `getBrowserManager()` creates a singleton `BrowserManager`.
3. The `BrowserManager` constructor calls `parseProxyConfig()`.
4. `parseProxyConfig()` checks `PROXY_URL` first, then falls back to `PROXY_HOST` and `PROXY_PORT`.
5. When Chromium launches, `BrowserManager.launchBrowser()` adds `--proxy-server=host:port`.
6. If username and password are configured, `browser-scraper.ts` calls `page.authenticate()` after `browser.newPage()`.
7. Product-page browser traffic then goes through the configured residential proxy.

## Browser Launch

The proxy server is attached to Chromium through a launch argument:

```ts
args.push(`--proxy-server=${proxyConfig.host}:${proxyConfig.port}`);
```

When a proxy is configured, Chromium is also launched with:

```ts
--ignore-certificate-errors
--ignore-certificate-errors-spki-list
```

Those flags are used because residential proxy providers can introduce certificate validation issues.

## Authentication

The proxy host and port are browser-level settings, but proxy credentials are page-level settings in Puppeteer. After creating a page, the scraper does:

```ts
await page.authenticate({
  username: proxyConfig.username,
  password: proxyConfig.password,
});
```

This means every product scrape page created through the shared browser manager gets authenticated before navigation.

## Where The Proxy Is Used

The proxy is used by normal browser product scraping through `scrapeFromUrlWithBrowser()` in `src/scrapers/browser-scraper.ts`. That scraper obtains the shared browser through:

```ts
const browserManager = getBrowserManager();
const browser = await browserManager.getBrowser(headlessMode);
```

Since the shared browser was launched with `--proxy-server`, its page traffic uses the configured Superproxy endpoint.

## Where The Proxy Is Not Used

Checkout scraping intentionally bypasses the proxy. `scrapeCheckoutWithBrowser()` creates a separate Puppeteer instance and launches Chromium without the `--proxy-server` argument.

That flow logs:

```text
Loading directly without proxy (checkout pages)
```

This is separate from the product scraping browser because checkout/payment pages are more sensitive to proxy-related CORS, authentication, and session issues.

## Proxy-Aware Scraping Behavior

The product scraper accounts for common proxy side effects:

- Navigation waits for `domcontentloaded` instead of `networkidle2`, because some CDN, analytics, or tracking resources may fail through the proxy.
- Console and request failure logging filters expected proxy noise such as CORS errors, Alibaba CDN failures, `Residential Failed`, `bad_endpoint`, and `402`.
- Images, fonts, and media are blocked to reduce proxy bandwidth and speed up scraping, while HTML, JavaScript, CSS, XHR, and fetch requests are allowed.

## Operational Notes

- Restart the running process after changing proxy environment variables. The proxy config is parsed when the `BrowserManager` singleton is created.
- If the existing browser is already running, it keeps using the proxy settings it was launched with.
- `PROXY_URL` parsing is simple `:` splitting, so passwords containing `:` or IPv6 hosts will not parse correctly without code changes.
- If no valid proxy config exists, the browser logs that it is using a direct connection.
- The non-browser scraper in `src/scrapers/alibaba-scraper.ts` does not currently use this proxy setup.

## Troubleshooting

Expected successful logs include:

```text
Found PROXY_URL environment variable
Parsed proxy config: host:port
Using residential proxy: host:port
Proxy authentication enabled
Proxy authentication set for this page
```

If scraping is direct instead, check that `PROXY_URL` or `PROXY_HOST` and `PROXY_PORT` are available to the process before startup.

If product scraping fails with proxy-provider messages like `Residential Failed`, `bad_endpoint`, or `402`, the scraper code is reaching the proxy, but the upstream proxy provider may be rejecting the request, zone, endpoint, balance, or credentials.
