<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Arr;
use RuntimeException;

class BrowserClickService
{
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

    private function client()
    {
        return $this->http
            ->baseUrl(rtrim((string) config('scraper.webdriver_url', 'http://127.0.0.1:9515'), '/'))
            ->timeout((int) config('scraper.browser_timeout', 30));
    }
}
