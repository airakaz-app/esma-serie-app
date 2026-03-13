<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class BrowserClickService
{
    private bool $attemptedDriverStart = false;

    private ?string $activeWebDriverUrl = null;

    private ?string $cachedWebDriverError = null;

    /**
     * @var array<int, Process>
     */
    private array $startedWebDriverProcesses = [];

    /**
     * @var array<int, string>|null
     */
    private ?array $resolvedPythonCommand = null;

    private ?string $cachedPythonResolutionError = null;

    public function __construct(private readonly Factory $http)
    {
    }

    /**
     * @return array{success:bool,final_url:?string,final_html:?string,error:?string}
     */
    public function resolveDownloadUrl(string $iframeUrl): array
    {
        $strategy = (string) config('scraper.browser_strategy', 'auto');

        if (in_array($strategy, ['python', 'auto'], true)) {
            $pythonResult = $this->resolveWithPythonBridge($iframeUrl);

            if ($pythonResult['success']) {
                return $pythonResult;
            }

            if ($strategy === 'python') {
                return $pythonResult;
            }

            Log::warning('Fallback vers WebDriver HTTP après échec Python bridge.', [
                'iframe_url' => $iframeUrl,
                'python_error' => $pythonResult['error'],
            ]);
        }

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

    /**
     * @return array{success:bool,final_url:?string,final_html:?string,error:?string}
     */
    private function resolveWithPythonBridge(string $iframeUrl): array
    {
        $scriptPath = base_path((string) config('scraper.python_script', 'browser_click.py'));

        if (! is_file($scriptPath)) {
            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => "Script Python introuvable: {$scriptPath}",
            ];
        }

        $pythonCommand = $this->resolvePythonCommand();
        if ($pythonCommand === null) {
            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => $this->cachedPythonResolutionError,
            ];
        }

        $process = new Process([
            ...$pythonCommand,
            $scriptPath,
            '--iframe-url',
            $iframeUrl,
            '--timeout',
            (string) config('scraper.browser_timeout', 30),
            '--headless',
            config('scraper.headless', true) ? '1' : '0',
        ], base_path());

        $process->setTimeout((int) config('scraper.python_timeout', 60));
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            Log::warning('Bridge Python Selenium en échec', [
                'iframe_url' => $iframeUrl,
                'python_command' => implode(' ', $pythonCommand),
                'error' => $error,
            ]);

            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => $error !== '' ? $error : 'Le processus Python a échoué.',
            ];
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => 'Réponse JSON invalide depuis la bridge Python.',
            ];
        }

        return [
            'success' => (bool) ($decoded['success'] ?? false),
            'final_url' => isset($decoded['final_url']) ? (string) $decoded['final_url'] : null,
            'final_html' => isset($decoded['final_html']) ? (string) $decoded['final_html'] : null,
            'error' => isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '' ? $decoded['error'] : null,
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function resolvePythonCommand(): ?array
    {
        if ($this->resolvedPythonCommand !== null) {
            return $this->resolvedPythonCommand;
        }

        $candidateCommands = collect([
            (string) config('scraper.python_binary', 'python3'),
            ...config('scraper.python_candidates', []),
        ])
            ->map(fn (string $command): string => trim($command))
            ->filter(fn (string $command): bool => $command !== '')
            ->unique()
            ->values();

        $errors = [];

        foreach ($candidateCommands as $candidateCommand) {
            $candidateArgs = preg_split('/\s+/', $candidateCommand) ?: [];
            if ($candidateArgs === []) {
                continue;
            }

            $versionProcess = new Process([
                ...$candidateArgs,
                '--version',
            ], base_path());

            $versionProcess->setTimeout(8);
            $versionProcess->run();

            if ($versionProcess->isSuccessful()) {
                $this->resolvedPythonCommand = $candidateArgs;

                Log::info('Commande Python détectée.', [
                    'command' => implode(' ', $candidateArgs),
                    'version' => trim($versionProcess->getOutput() ?: $versionProcess->getErrorOutput()),
                ]);

                return $this->resolvedPythonCommand;
            }

            $error = trim($versionProcess->getErrorOutput()) ?: trim($versionProcess->getOutput());
            $errors[] = sprintf('%s => %s', $candidateCommand, $error !== '' ? $error : 'indisponible');
        }

        $this->cachedPythonResolutionError = sprintf(
            'Aucun interpréteur Python utilisable. Commandes testées: %s. Configurez SCRAPER_PYTHON_CANDIDATES (ex: "py -3,python,python3") ou SCRAPER_BROWSER_STRATEGY=webdriver.',
            implode(' | ', $errors)
        );

        return null;
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
        if ($this->activeWebDriverUrl !== null) {
            return;
        }

        if ($this->cachedWebDriverError !== null) {
            throw new RuntimeException($this->cachedWebDriverError);
        }

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

        $this->cachedWebDriverError = sprintf(
            'WebDriver indisponible. URLs testées: %s. Lancez Selenium/Chromedriver, ou vérifiez SCRAPER_WEBDRIVER_URL / SCRAPER_WEBDRIVER_BINARY.',
            implode(', ', $candidates)
        );

        throw new RuntimeException($this->cachedWebDriverError);
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
        ]);

        foreach ($binaryCandidates as $binary) {
            $process = new Process([
                $binary,
                "--port={$port}",
            ], base_path());

            $process->start();
            $this->startedWebDriverProcesses[] = $process;

            Log::info('Process auto-start WebDriver lancé', [
                'binary' => $binary,
                'pid' => $process->getPid(),
            ]);

            $deadline = microtime(true) + (float) config('scraper.webdriver_boot_timeout', 8);
            while (microtime(true) < $deadline) {
                foreach ($this->candidateWebDriverUrls() as $candidateUrl) {
                    if ($this->isWebDriverReachable($candidateUrl)) {
                        $this->activeWebDriverUrl = $candidateUrl;
                        Log::info('Auto-start WebDriver réussi', [
                            'binary' => $binary,
                            'pid' => $process->getPid(),
                            'webdriver_url' => $candidateUrl,
                        ]);

                        return;
                    }
                }

                usleep(250_000);
            }

            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            Log::warning('Auto-start échoué pour binaire', [
                'binary' => $binary,
                'pid' => $process->getPid(),
                'output' => mb_substr($output, 0, 600),
            ]);

            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        throw new RuntimeException(
            sprintf(
                'Échec auto-démarrage WebDriver. Binaires testés: %s.',
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
