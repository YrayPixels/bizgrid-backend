<?php

namespace App\Console\Commands;

use App\Http\Controllers\KalshiScraperController;
use Illuminate\Console\Command;
use ReflectionMethod;

class KalshiBrowserCommand extends Command
{
    protected $signature = 'kalshi:browser
                            {query=nba : Kalshi search query}
                            {--category= : Optional category filter}
                            {--slow=0 : Puppeteer slowMo (ms) between actions; only when headed}';

    protected $description = 'Open Kalshi search in a visible Chromium window (headed Puppeteer)';

    public function handle(): int
    {
        putenv('KALSHI_PUPPETEER_HEADED=true');
        $_ENV['KALSHI_PUPPETEER_HEADED'] = 'true';
        $_SERVER['KALSHI_PUPPETEER_HEADED'] = 'true';

        $slow = (int) $this->option('slow');
        if ($slow > 0) {
            $s = (string) $slow;
            putenv('KALSHI_PUPPETEER_SLOW_MO_MS='.$s);
            $_ENV['KALSHI_PUPPETEER_SLOW_MO_MS'] = $s;
            $_SERVER['KALSHI_PUPPETEER_SLOW_MO_MS'] = $s;
        }

        $query = (string) $this->argument('query');
        $category = $this->option('category');
        $category = $category !== null && $category !== '' ? (string) $category : null;

        $this->info('Launching visible Chromium — query: "'.$query.'"');

        $controller = new KalshiScraperController();
        $method = new ReflectionMethod(KalshiScraperController::class, 'fetchKalshiSearchHtmlViaPuppeteer');
        $method->setAccessible(true);

        try {
            $html = $method->invoke($controller, $query, $category);
            $hasTiles = str_contains($html, 'data-testid="market-tile"');
            $this->info('HTML length: '.strlen($html));
            $this->info($hasTiles ? 'Market tiles found.' : 'No market-tile markers in HTML (checkpoint, timeout, or layout change).');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
