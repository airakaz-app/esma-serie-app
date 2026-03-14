<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
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

        if (in_array($strategy, ['http', 'auto'], true)) {
            $httpResult = $this->resolveWithHttpOnlyStrategy($iframeUrl);

            if ($httpResult['success']) {
                return $httpResult;
            }

            if ($strategy === 'http') {
                return $httpResult;
            }

            Log::warning('Fallback vers navigateur après échec stratégie HTTP-only.', [
                'iframe_url' => $iframeUrl,
                'http_error' => $httpResult['error'],
            ]);
        }

        $pythonError = null;

        if (in_array($strategy, ['python', 'auto'], true)) {
            $pythonResult = $this->resolveWithPythonBridge($iframeUrl);

            if ($pythonResult['success']) {
                return $pythonResult;
            }

            $pythonError = $pythonResult['error'];

            if ($strategy === 'python') {
                Log::warning('Fallback HTTP-only après échec Python bridge (stratégie python).', [
                    'iframe_url' => $iframeUrl,
                    'python_error' => $pythonResult['error'],
                ]);

                $httpFallbackResult = $this->resolveWithHttpOnlyStrategy($iframeUrl);

                if ($httpFallbackResult['success']) {
                    return $httpFallbackResult;
                }

                $pythonResult['error'] = sprintf(
                    'Python bridge: %s | HTTP-only fallback: %s',
                    $pythonResult['error'] ?? 'erreur inconnue',
                    $httpFallbackResult['error'] ?? 'erreur inconnue'
                );

                return $pythonResult;
            }

            if (! $this->shouldAttemptWebDriverFallback($pythonResult['error'])) {
                Log::warning('Fallback WebDriver ignoré après échec Python bridge.', [
                    'iframe_url' => $iframeUrl,
                    'python_error' => $pythonResult['error'],
                ]);

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
            $errorMessage = $e->getMessage();

            if (is_string($pythonError) && $pythonError !== '') {
                $errorMessage = sprintf('Python bridge en échec: %s | Fallback WebDriver: %s', $pythonError, $errorMessage);
            }

            Log::error('Erreur BrowserClickService::resolveDownloadUrl', [
                'iframe_url' => $iframeUrl,
                'webdriver_url' => $this->activeWebDriverUrl,
                'error' => $errorMessage,
            ]);

            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => $errorMessage,
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
    private function resolveWithHttpOnlyStrategy(string $iframeUrl): array
    {
        try {
            $response = $this->httpClientForScraping($iframeUrl)
                ->get($iframeUrl)
                ->throw();
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => sprintf('HTTP-only: impossible de récupérer l\'iframe (%s).', $exception->getMessage()),
            ];
        }

        $currentUrl = $response->effectiveUri()?->getUri() ?? $iframeUrl;
        $html = (string) $response->body();

        $stepOne = $this->findFormStepByTriggerId($html, $currentUrl, 'method_free');
        if ($stepOne !== null) {
            $response = $this->submitHttpStep($stepOne, $iframeUrl);
            $currentUrl = $response->effectiveUri()?->getUri() ?? $stepOne['action'];
            $html = (string) $response->body();
        }

        $stepTwo = $this->findFormStepByTriggerId($html, $currentUrl, 'downloadbtn');
        if ($stepTwo !== null) {
            $response = $this->submitHttpStep($stepTwo, $iframeUrl);
            $currentUrl = $response->effectiveUri()?->getUri() ?? $stepTwo['action'];
            $html = (string) $response->body();
        }

        $resolvedUrl = $this->extractFinalUrlFromHttpHtml($html, $currentUrl);

        if ($resolvedUrl === null && $this->looksLikeDownloadCandidate($currentUrl)) {
            $resolvedUrl = $currentUrl;
        }

        if ($resolvedUrl === null) {
            return [
                'success' => false,
                'final_url' => null,
                'final_html' => $html,
                'error' => 'HTTP-only: aucun endpoint exploitable détecté après simulation method_free/downloadbtn.',
            ];
        }

        return [
            'success' => true,
            'final_url' => $resolvedUrl,
            'final_html' => $html,
            'error' => null,
        ];
    }

    /**
     * @return array{action:string,method:string,payload:array<string,string>}|null
     */
    private function findFormStepByTriggerId(string $html, string $baseUrl, string $triggerId): ?array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $trigger = $xpath->query(sprintf('//*[@id="%s"]', $triggerId))?->item(0);
        if (! $trigger instanceof \DOMElement) {
            return null;
        }

        $form = $this->nearestFormElement($trigger);
        if (! $form instanceof \DOMElement) {
            return null;
        }

        $rawAction = trim((string) $form->getAttribute('action'));
        $action = $rawAction !== '' ? ($this->toAbsoluteUrl($rawAction, $baseUrl) ?? $baseUrl) : $baseUrl;
        $method = Str::upper(trim((string) $form->getAttribute('method')) ?: 'GET');

        $payload = [];
        foreach ($xpath->query('.//input', $form) ?: [] as $inputNode) {
            if (! $inputNode instanceof \DOMElement) {
                continue;
            }

            $name = trim((string) $inputNode->getAttribute('name'));
            if ($name === '') {
                continue;
            }

            $type = Str::lower(trim((string) $inputNode->getAttribute('type')) ?: 'text');
            if ($type === 'submit' || $type === 'button') {
                continue;
            }

            $payload[$name] = (string) $inputNode->getAttribute('value');
        }

        if ($trigger->hasAttribute('name')) {
            $triggerName = trim((string) $trigger->getAttribute('name'));
            if ($triggerName !== '') {
                $payload[$triggerName] = trim((string) $trigger->getAttribute('value'));
            }
        }

        return [
            'action' => $action,
            'method' => in_array($method, ['GET', 'POST'], true) ? $method : 'GET',
            'payload' => $payload,
        ];
    }

    private function nearestFormElement(\DOMElement $node): ?\DOMElement
    {
        $current = $node;

        while ($current->parentNode instanceof \DOMElement) {
            $current = $current->parentNode;
            if (Str::lower($current->tagName) === 'form') {
                return $current;
            }
        }

        return null;
    }

    private function submitHttpStep(array $step, string $refererUrl): \Illuminate\Http\Client\Response
    {
        $client = $this->httpClientForScraping($refererUrl);

        if ($step['method'] === 'POST') {
            return $client->asForm()->post($step['action'], $step['payload'])->throw();
        }

        return $client->get($step['action'], $step['payload'])->throw();
    }

    private function extractFinalUrlFromHttpHtml(string $html, string $baseUrl): ?string
    {
        $urlCandidates = $this->extractUrlCandidates($html, $baseUrl);

        if ($urlCandidates !== []) {
            return $urlCandidates[0];
        }

        $tokenCandidates = $this->extractTokenCandidates($html);
        foreach ($tokenCandidates as $tokenCandidate) {
            $queryGlue = Str::contains($baseUrl, '?') ? '&' : '?';
            $constructedCandidate = $baseUrl.$queryGlue.$tokenCandidate;

            if (filter_var($constructedCandidate, FILTER_VALIDATE_URL)) {
                return $constructedCandidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractUrlCandidates(string $html, string $baseUrl): array
    {
        $patterns = [
            '/https?:\\/\\/[^"\'\s<>]+/i',
            '/["\'](?:url|file|src|href|link|download|endpoint|api|ajax|xhr)["\']\s*[:=]\s*["\']([^"\']+)["\']/i',
            '/fetch\s*\(\s*["\']([^"\']+)["\']/i',
            '/axios\.(?:get|post)\s*\(\s*["\']([^"\']+)["\']/i',
            '/(?:window\.)?location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i',
        ];

        $candidates = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $matches);
            foreach ($matches[1] ?? $matches[0] ?? [] as $rawCandidate) {
                $normalized = trim((string) $rawCandidate);
                if ($normalized === '' || Str::startsWith($normalized, ['#', 'javascript:'])) {
                    continue;
                }

                $absolute = $this->toAbsoluteUrl($normalized, $baseUrl);

                if ($absolute === null || ! filter_var($absolute, FILTER_VALIDATE_URL)) {
                    continue;
                }

                if ($this->looksLikeDownloadCandidate($absolute)) {
                    $candidates[] = $absolute;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<int, string>
     */
    private function extractTokenCandidates(string $html): array
    {
        $patterns = [
            '/["\'](?:token|signature|sig|hash|key|auth)["\']\s*[:=]\s*["\']([^"\']+)["\']/i',
            '/(?:token|signature|sig|hash|key|auth)=([A-Za-z0-9._\-]+)/i',
        ];

        $candidates = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $matches);
            foreach ($matches[1] ?? [] as $value) {
                $normalized = trim((string) $value);
                if ($normalized === '') {
                    continue;
                }

                $candidates[] = sprintf('token=%s', urlencode($normalized));
            }
        }

        return array_values(array_unique($candidates));
    }

    private function looksLikeDownloadCandidate(string $url): bool
    {
        $normalizedUrl = Str::lower($url);

        return Str::contains($normalizedUrl, [
            '.mp4',
            '.m3u8',
            '.mkv',
            '.avi',
            '.mov',
            'download',
            'stream',
            'video',
            'file=',
            'token=',
            'signature=',
            'sig=',
        ]);
    }

    private function toAbsoluteUrl(string $candidateUrl, string $baseUrl): ?string
    {
        if (filter_var($candidateUrl, FILTER_VALIDATE_URL)) {
            return $candidateUrl;
        }

        if (Str::startsWith($candidateUrl, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return sprintf('%s:%s', $scheme, $candidateUrl);
        }

        if (! Str::startsWith($candidateUrl, ['/'])) {
            return null;
        }

        $baseParts = parse_url($baseUrl);
        if (! is_array($baseParts) || ! isset($baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'] ?? 'https';
        $portPart = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        return sprintf('%s://%s%s%s', $scheme, $baseParts['host'], $portPart, $candidateUrl);
    }

    private function httpClientForScraping(string $refererUrl): PendingRequest
    {
        return $this->http
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Referer' => $refererUrl,
            ])
            ->withOptions(['allow_redirects' => true])
            ->timeout((int) config('scraper.http_timeout', 20));
    }

    private function shouldAttemptWebDriverFallback(?string $pythonError): bool
    {
        if (! is_string($pythonError) || trim($pythonError) === '') {
            return true;
        }

        $normalizedError = mb_strtolower($pythonError);

        $nonRecoverablePatterns = [
            'timeoutexception',
            'nosuchelementexception',
            'elementclickinterceptedexception',
            'downloadbtn',
            'method_free',
        ];

        $infraPatterns = [
            'webdriver',
            'chromedriver',
            'selenium-manager',
            'unable to obtain driver',
            'failed to establish a new connection',
            'connection refused',
            'status code was: -5',
        ];

        $hasInfrastructureMarker = false;

        foreach ($infraPatterns as $pattern) {
            if (str_contains($normalizedError, $pattern)) {
                $hasInfrastructureMarker = true;
                break;
            }
        }

        if (! $hasInfrastructureMarker) {
            foreach ($nonRecoverablePatterns as $pattern) {
                if (str_contains($normalizedError, $pattern)) {
                    return false;
                }
            }
        }

        $recoverablePatterns = [
            'aucun webdriver disponible',
            'webdriver indisponible',
            'unable to obtain driver for chrome',
            'failed to establish a new connection',
            'connection refused',
            'chromedriver unexpectedly exited',
            'status code was: -5',
        ];

        foreach ($recoverablePatterns as $pattern) {
            if (str_contains($normalizedError, $pattern)) {
                return true;
            }
        }

        return ! in_array(true, array_map(
            static fn (string $pattern): bool => str_contains($normalizedError, $pattern),
            $nonRecoverablePatterns
        ), true);
    }

    /**
     * @return array{success:bool,final_url:?string,final_html:?string,error:?string}
     */
    private function resolveWithPythonBridge(string $iframeUrl): array
    {
        $scriptPath = base_path((string) config('scraper.python_script', 'browser_click.py'));
        $startedAt = microtime(true);

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

        $pythonTimeout = $this->resolvePythonTimeout();
        $process->setTimeout($pythonTimeout);
        $process->setIdleTimeout(null);

        Log::info('Démarrage bridge Python Selenium.', [
            'iframe_url' => $iframeUrl,
            'python_command' => implode(' ', [...$pythonCommand, $scriptPath]),
            'browser_timeout' => (int) config('scraper.browser_timeout', 30),
            'configured_python_timeout' => (float) config('scraper.python_timeout', 0),
            'python_timeout' => $pythonTimeout,
            'headless' => config('scraper.headless', true),
        ]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::error('Bridge Python timeout.', [
                'iframe_url' => $iframeUrl,
                'duration_ms' => $durationMs,
                'python_timeout' => $pythonTimeout,
                'output_preview' => mb_substr(trim($process->getOutput()), 0, 800),
                'error_preview' => mb_substr(trim($process->getErrorOutput()), 0, 800),
            ]);

            return [
                'success' => false,
                'final_url' => null,
                'final_html' => null,
                'error' => sprintf('Bridge Python timeout après %.2fs (limite %.2fs).', $durationMs / 1000, $pythonTimeout),
            ];
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('Fin bridge Python Selenium.', [
            'iframe_url' => $iframeUrl,
            'duration_ms' => $durationMs,
            'exit_code' => $process->getExitCode(),
            'successful' => $process->isSuccessful(),
            'output_preview' => mb_substr(trim($process->getOutput()), 0, 400),
            'error_preview' => mb_substr(trim($process->getErrorOutput()), 0, 400),
        ]);

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

    private function resolvePythonTimeout(): float
    {
        $configuredTimeout = (float) config('scraper.python_timeout', 0);
        $minimumSafeTimeout = max(((float) config('scraper.browser_timeout', 30)) * 4.0, 180.0);

        if ($configuredTimeout <= 0) {
            return $minimumSafeTimeout;
        }

        if ($configuredTimeout < $minimumSafeTimeout) {
            Log::warning('SCRAPER_PYTHON_TIMEOUT trop bas, application du minimum de sécurité.', [
                'configured_timeout' => $configuredTimeout,
                'minimum_safe_timeout' => $minimumSafeTimeout,
            ]);

            return $minimumSafeTimeout;
        }

        return $configuredTimeout;
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
            $candidateArgs = $this->expandPythonCommandToken($candidateCommand);
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
            'Aucun interpréteur Python utilisable. Commandes testées: %s. Configurez SCRAPER_PYTHON_CANDIDATES (ex: "py3,python,python3") ou SCRAPER_BROWSER_STRATEGY=webdriver.',
            implode(' | ', $errors)
        );

        return null;
    }


    /**
     * @return array<int, string>
     */
    private function expandPythonCommandToken(string $candidateCommand): array
    {
        if ($candidateCommand === 'py3' || $candidateCommand === 'py-3') {
            return ['py', '-3'];
        }

        return preg_split('/\s+/', $candidateCommand) ?: [];
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
