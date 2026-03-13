<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BrowserClickService
{
    private bool $attemptedDriverStart = false;

    private ?string $activeWebDriverUrl = null;

    public function __construct(private readonly Factory $http)
    {
    }

    /**
     * @return array{success:bool,final_url:?string,final_html:?string,error:?string}
     */
    public function resolveDownloadUrl(string $iframeUrl): array
    {
        $sessionId = null;

        try {
            $this->ensureWebDriverIsReachable();

            Log::info('WebDriver prêt, démarrage session scraping.', [
                'webdriver_url' => $this->activeWebDriverUrl,
                'iframe_url' => $iframeUrl,
            ]);

            $sessionId = $this->createSession();
            $this->navigate($sessionId, $iframeUrl);
            $this->waitForReady($sessionId);

            $this->clickById($sessionId, 'method_free');
            usleep(800_000);
            $this->clickById($sessionId, 'downloadbtn');

            $this->waitForReady($sessionId);
            $this->waitForStableUrl($sessionId);

            return [
                'success' => true,
                'final_url' => $this->currentUrl($sessionId),
                'final_html' => $this->pageHtml($sessionId),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('Erreur BrowserClickService::resolveDownloadUrl', [
                'iframe_url' => $iframeUrl,
                'webdriver_url' => $this->activeWebDriverUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($sessionId !== null) {
                $this->client()->delete("/session/{$sessionId}");
            }
        }
    }

    public function summarizeHtml(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        $title = $dom->getElementsByTagName('title')->item(0)?->textContent;
        $h1 = $dom->getElementsByTagName('h1')->item(0)?->textContent;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        return [
            'result_title' => $title ? trim($title) : null,
            'result_h1' => $h1 ? trim($h1) : null,
            'result_preview' => mb_substr($text, 0, 400),
        ];
    }

    private function ensureWebDriverIsReachable(): void
    {
        $candidates = $this->candidateWebDriverUrls();
        Log::info('Test disponibilité WebDriver', ['urls' => $candidates]);

        foreach ($candidates as $candidateUrl) {
            if ($this->isWebDriverReachable($candidateUrl)) {
                $this->activeWebDriverUrl = $candidateUrl;
                Log::info('WebDriver détecté.', ['webdriver_url' => $candidateUrl]);

                return;
            }
        }

        if ((bool) config('scraper.webdriver_autostart', true)) {
            $this->startLocalWebDriver();

            foreach ($candidates as $candidateUrl) {
                if ($this->isWebDriverReachable($candidateUrl)) {
                    $this->activeWebDriverUrl = $candidateUrl;
                    Log::info('WebDriver détecté après auto-start.', ['webdriver_url' => $candidateUrl]);

                    return;
                }
            }
        }

        throw new RuntimeException(
            sprintf(
                'WebDriver indisponible. URLs testées: %s. Lancez Selenium/Chromedriver, ou vérifiez SCRAPER_WEBDRIVER_URL / SCRAPER_WEBDRIVER_BINARY.',
                implode(', ', $candidates)
            )
        );
    }

    /**
     * @return array<int,string>
     */
    private function candidateWebDriverUrls(): array
    {
        $configured = rtrim((string) config('scraper.webdriver_url', 'http://127.0.0.1:9515'), '/');
        $fallbacks = collect(config('scraper.webdriver_fallback_urls', []))
            ->map(fn (string $url): string => rtrim($url, '/'))
            ->all();

        return array_values(array_unique(array_filter([
            $configured,
            'http://127.0.0.1:9515',
            ...$fallbacks,
        ])));
    }

    private function isWebDriverReachable(string $baseUrl): bool
    {
        try {
            $response = $this->client($baseUrl)->get('/status');

            if ($response->successful()) {
                return true;
            }

            Log::warning('WebDriver /status non successful', [
                'webdriver_url' => $baseUrl,
                'http_status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 400),
            ]);

            return false;
        } catch (ConnectionException $e) {
            Log::warning('WebDriver non joignable', [
                'webdriver_url' => $baseUrl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function startLocalWebDriver(): void
    {
        if ($this->attemptedDriverStart) {
            Log::info('Auto-start WebDriver déjà tenté, pas de nouvelle tentative.');

            return;
        }

        $this->attemptedDriverStart = true;

        $binaryCandidates = $this->binaryCandidates();
        $port = $this->extractPortFromPrimaryUrl();

        Log::info('Tentative auto-start WebDriver', [
            'port' => $port,
            'binaries' => $binaryCandidates,
            'which_chromedriver' => trim((string) shell_exec('command -v chromedriver 2>/dev/null')),
            'which_chromium_driver' => trim((string) shell_exec('command -v chromium-driver 2>/dev/null')),
        ]);

        foreach ($binaryCandidates as $binary) {
            $command = sprintf(
                '%s --port=%d --allowed-ips="" --allowed-origins="*" --url-base=/ >/tmp/scraper-chromedriver.log 2>&1 & echo $!',
                escapeshellcmd($binary),
                $port,
            );

            $pid = trim((string) shell_exec($command));

            Log::info('Commande auto-start exécutée', [
                'binary' => $binary,
                'command' => $command,
                'pid' => $pid,
            ]);

            $deadline = microtime(true) + (float) config('scraper.webdriver_boot_timeout', 8);
            while (microtime(true) < $deadline) {
                foreach ($this->candidateWebDriverUrls() as $candidateUrl) {
                    if ($this->isWebDriverReachable($candidateUrl)) {
                        $this->activeWebDriverUrl = $candidateUrl;
                        Log::info('Auto-start WebDriver réussi', [
                            'binary' => $binary,
                            'pid' => $pid,
                            'webdriver_url' => $candidateUrl,
                        ]);

                        return;
                    }
                }

                usleep(250_000);
            }

            Log::warning('Auto-start échoué pour binaire', [
                'binary' => $binary,
                'pid' => $pid,
                'chromedriver_log_tail' => trim((string) shell_exec('tail -n 30 /tmp/scraper-chromedriver.log 2>/dev/null')),
            ]);
        }

        throw new RuntimeException(
            sprintf(
                'Échec auto-démarrage WebDriver. Binaires testés: %s. Consultez /tmp/scraper-chromedriver.log',
                implode(', ', $binaryCandidates)
            )
        );
    }

    /**
     * @return array<int,string>
     */
    private function binaryCandidates(): array
    {
        $configured = trim((string) config('scraper.webdriver_binary', 'chromedriver'));

        return array_values(array_unique(array_filter([
            $configured,
            'chromedriver',
            'chromium-driver',
        ])));
    }

    private function extractPortFromPrimaryUrl(): int
    {
        $parts = parse_url((string) config('scraper.webdriver_url', 'http://127.0.0.1:9515'));

        return (int) ($parts['port'] ?? 9515);
    }

    private function createSession(): string
    {
        $payload = [
            'capabilities' => [
                'alwaysMatch' => [
                    'browserName' => 'chrome',
                    'goog:chromeOptions' => [
                        'args' => array_values(array_filter([
                            config('scraper.headless', true) ? '--headless=new' : null,
                            '--disable-blink-features=AutomationControlled',
                            '--no-sandbox',
                            '--disable-dev-shm-usage',
                            '--window-size=1920,1080',
                        ])),
                    ],
                ],
            ],
        ];

        $response = $this->client()->post('/session', $payload)->throw()->json();
        $sessionId = Arr::get($response, 'value.sessionId', Arr::get($response, 'sessionId'));

        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Impossible de créer une session WebDriver.');
        }

        return $sessionId;
    }

    private function navigate(string $sessionId, string $url): void
    {
        $this->client()->post("/session/{$sessionId}/url", ['url' => $url])->throw();
    }

    private function clickById(string $sessionId, string $id): void
    {
        $element = $this->waitForElement($sessionId, $id);
        $this->client()->post("/session/{$sessionId}/element/{$element}/click", [])->throw();
    }

    private function waitForElement(string $sessionId, string $id): string
    {
        $timeout = now()->addSeconds((int) config('scraper.browser_timeout', 30));

        while (now()->lessThan($timeout)) {
            $response = $this->client()->post("/session/{$sessionId}/element", [
                'using' => 'css selector',
                'value' => '#'.$id,
            ]);

            if ($response->successful()) {
                $value = $response->json('value', []);
                $element = $value['element-6066-11e4-a52e-4f735466cecf'] ?? $value['ELEMENT'] ?? null;
                if (is_string($element) && $element !== '') {
                    return $element;
                }
            }

            usleep(250_000);
        }

        throw new RuntimeException("Élément #{$id} introuvable");
    }

    private function waitForReady(string $sessionId): void
    {
        $timeout = now()->addSeconds((int) config('scraper.browser_timeout', 30));

        while (now()->lessThan($timeout)) {
            $state = $this->executeScript($sessionId, 'return document.readyState;');
            if ($state === 'complete') {
                return;
            }

            usleep(300_000);
        }

        throw new RuntimeException('Timeout en attente du chargement de page.');
    }

    private function waitForStableUrl(string $sessionId): void
    {
        $timeout = now()->addSeconds((int) config('scraper.browser_timeout', 30));
        $lastUrl = null;
        $stableTicks = 0;

        while (now()->lessThan($timeout)) {
            $current = $this->currentUrl($sessionId);

            if ($current !== null && $current === $lastUrl) {
                $stableTicks++;
            } else {
                $stableTicks = 0;
            }

            if ($stableTicks >= 3) {
                return;
            }

            $lastUrl = $current;
            usleep(400_000);
        }
    }

    private function currentUrl(string $sessionId): ?string
    {
        return $this->client()->get("/session/{$sessionId}/url")->throw()->json('value');
    }

    private function pageHtml(string $sessionId): ?string
    {
        $html = $this->executeScript($sessionId, 'return document.documentElement.outerHTML;');

        return is_string($html) ? $html : null;
    }

    private function executeScript(string $sessionId, string $script): mixed
    {
        return $this->client()->post("/session/{$sessionId}/execute/sync", [
            'script' => $script,
            'args' => [],
        ])->throw()->json('value');
    }

    private function client(?string $baseUrl = null): PendingRequest
    {
        $url = rtrim($baseUrl ?? $this->activeWebDriverUrl ?? (string) config('scraper.webdriver_url', 'http://127.0.0.1:9515'), '/');

        return $this->http
            ->baseUrl($url)
            ->timeout((int) config('scraper.browser_timeout', 30));
    }
}
