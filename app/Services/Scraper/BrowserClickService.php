<?php

namespace App\Services\Scraper;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class BrowserClickService
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const DOWNLOAD_EXTENSIONS = ['mp4', 'm3u8', 'mkv', 'avi', 'mov', 'webm', 'ts'];

    public function __construct(private readonly Factory $http) {}

    /**
     * @return array{success:bool,final_url:?string,final_html:?string,error:?string}
     */
    public function resolveDownloadUrl(string $iframeUrl, ?string $referer = null): array
    {
        $jar     = new CookieJar();
        $referer = $referer ?? $iframeUrl;

        Log::info('BrowserClick: début résolution.', ['iframe_url' => $iframeUrl, 'referer' => $referer]);

        try {
            // Étape 1 : charger la page iframe
            $response   = $this->client($referer, $jar)->get($iframeUrl)->throw();
            $currentUrl = (string) ($response->effectiveUri() ?? $iframeUrl);
            $html       = $response->body();

            Log::info('BrowserClick: page chargée.', [
                'current_url' => $currentUrl,
                'html_length' => mb_strlen($html),
            ]);

            // Étape 2 : clic method_free (soumet le formulaire step 1)
            [$html, $currentUrl] = $this->simulateClick($html, $currentUrl, 'method_free', $jar);

            Log::info('BrowserClick: après method_free.', [
                'current_url'  => $currentUrl,
                'html_length'  => mb_strlen($html),
                'has_downloadbtn' => str_contains($html, 'downloadbtn'),
                'html_preview' => mb_substr(strip_tags($html), 0, 200),
            ]);

            // Tentative immédiate : URL déjà présente dans la page intermédiaire
            $earlyUrl = $this->extractFinalUrl($html, $currentUrl);
            if ($earlyUrl !== null) {
                Log::info('BrowserClick: URL finale dans HTML intermédiaire.', ['final_url' => $earlyUrl]);

                return ['success' => true, 'final_url' => $earlyUrl, 'final_html' => $html, 'error' => null];
            }

            // Petit délai pour éviter le rate-limit vdesk
            usleep(800_000);

            // Étape 3 : clic downloadbtn (soumet le formulaire step 2)
            //           Le serveur répond avec un redirect 302 vers l'URL finale.
            //           On désactive le suivi pour capturer directement le header Location.
            $finalUrl = $this->submitFinalStep($html, $currentUrl, 'downloadbtn', $jar);

            if ($finalUrl !== null) {
                Log::info('BrowserClick: URL finale trouvée via redirect.', ['final_url' => $finalUrl]);

                return ['success' => true, 'final_url' => $finalUrl, 'final_html' => $html, 'error' => null];
            }

            // Fallback : chercher l'URL dans le HTML si pas de redirect
            $finalUrl = $this->extractFinalUrl($html, $currentUrl);

            if ($finalUrl === null) {
                Log::warning('BrowserClick: aucune URL finale détectée.', ['current_url' => $currentUrl]);

                return [
                    'success'    => false,
                    'final_url'  => null,
                    'final_html' => $html,
                    'error'      => 'Aucun lien de téléchargement détecté.',
                ];
            }

            Log::info('BrowserClick: URL finale dans HTML.', ['final_url' => $finalUrl]);

            return ['success' => true, 'final_url' => $finalUrl, 'final_html' => $html, 'error' => null];

        } catch (\Throwable $e) {
            Log::error('BrowserClick: erreur fatale.', ['iframe_url' => $iframeUrl, 'error' => $e->getMessage()]);

            return ['success' => false, 'final_url' => null, 'final_html' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Soumet le formulaire final SANS suivre le redirect.
     * Retourne l'URL du header Location (URL de téléchargement directe).
     */
    private function submitFinalStep(string $html, string $currentUrl, string $id, CookieJar $jar): ?string
    {
        $crawler = new Crawler($html, $currentUrl);

        try {
            $node = $crawler->filter("#{$id}");
        } catch (\Exception) {
            return null;
        }

        if ($node->count() === 0) {
            Log::warning("BrowserClick: élément #{$id} introuvable pour étape finale.");

            return null;
        }

        $formStep = $this->extractFormStep($node, $currentUrl);
        if ($formStep === null) {
            return null;
        }

        try {
            // Soumettre SANS suivre les redirects pour capturer Location
            $client = $this->clientNoRedirect($currentUrl, $jar);
            $response = $formStep['method'] === 'POST'
                ? $client->asForm()->post($formStep['action'], $formStep['payload'])
                : $client->get($formStep['action'], $formStep['payload']);

            $status   = $response->status();
            $location = $response->header('Location');

            Log::info("BrowserClick: form #{$id} soumis (no-redirect).", [
                'action'   => $formStep['action'],
                'status'   => $status,
                'location' => $location,
            ]);

            // 302/301 avec Location = URL finale directe
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $abs = $this->toAbsolute($location, $formStep['action']);

                return $abs;
            }

            // Réponse 200 directe (rare) : chercher dans le body
            return $this->extractFinalUrl($response->body(), (string) ($response->effectiveUri() ?? $formStep['action']));

        } catch (\Throwable $e) {
            Log::warning("BrowserClick: étape finale #{$id} échouée.", ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Simule un clic intermédiaire (method_free, etc.) en suivant les redirects normalement.
     *
     * @return array{0:string,1:string}
     */
    private function simulateClick(string $html, string $currentUrl, string $id, CookieJar $jar): array
    {
        $crawler = new Crawler($html, $currentUrl);

        try {
            $node = $crawler->filter("#{$id}");
        } catch (\Exception) {
            Log::warning("BrowserClick: sélecteur #{$id} invalide.");

            return [$html, $currentUrl];
        }

        if ($node->count() === 0) {
            Log::warning("BrowserClick: élément #{$id} introuvable.");

            return [$html, $currentUrl];
        }

        // Stratégie 1 : formulaire HTML parent
        $formStep = $this->extractFormStep($node, $currentUrl);
        if ($formStep !== null) {
            try {
                $response = $this->submitStep($formStep, $currentUrl, $jar);
                $newUrl   = (string) ($response->effectiveUri() ?? $formStep['action']);
                Log::info("BrowserClick: form #{$id} soumis.", ['action' => $formStep['action'], 'new_url' => $newUrl]);

                return [$response->body(), $newUrl];
            } catch (\Throwable $e) {
                Log::warning("BrowserClick: form #{$id} échoué.", ['error' => $e->getMessage()]);
            }
        }

        // Stratégie 2 : onclick JavaScript
        $ajaxStep = $this->extractOnclickStep($node, $currentUrl);
        if ($ajaxStep !== null) {
            try {
                $response = $this->submitStep($ajaxStep, $currentUrl, $jar);
                $newUrl   = (string) ($response->effectiveUri() ?? $ajaxStep['action']);
                Log::info("BrowserClick: onclick #{$id} résolu.", ['action' => $ajaxStep['action'], 'new_url' => $newUrl]);

                return [$response->body(), $newUrl];
            } catch (\Throwable $e) {
                Log::warning("BrowserClick: onclick #{$id} échoué.", ['error' => $e->getMessage()]);
            }
        }

        // Stratégie 3 : attributs data-url / href
        $dataUrl = $this->extractDataUrl($node, $currentUrl);
        if ($dataUrl !== null) {
            try {
                $response = $this->client($currentUrl, $jar)->get($dataUrl)->throw();
                $newUrl   = (string) ($response->effectiveUri() ?? $dataUrl);
                Log::info("BrowserClick: data-url #{$id} suivi.", ['url' => $dataUrl, 'new_url' => $newUrl]);

                return [$response->body(), $newUrl];
            } catch (\Throwable $e) {
                Log::warning("BrowserClick: data-url #{$id} échoué.", ['error' => $e->getMessage()]);
            }
        }

        Log::warning("BrowserClick: aucune stratégie applicable pour #{$id}.");

        return [$html, $currentUrl];
    }

    /**
     * @return array{action:string,method:string,payload:array<string,string>}|null
     */
    private function extractFormStep(Crawler $node, string $baseUrl): ?array
    {
        $formNode = $node->closest('form');
        if ($formNode === null || $formNode->count() === 0) {
            return null;
        }

        $rawAction = trim($formNode->attr('action') ?? '');
        $action    = $rawAction !== '' ? ($this->toAbsolute($rawAction, $baseUrl) ?? $baseUrl) : $baseUrl;
        $method    = strtoupper(trim($formNode->attr('method') ?? 'GET'));
        $method    = in_array($method, ['GET', 'POST'], true) ? $method : 'GET';

        $payload = [];
        $formNode->filter('input')->each(function (Crawler $input) use (&$payload): void {
            $name = trim($input->attr('name') ?? '');
            if ($name === '') {
                return;
            }
            $type = strtolower(trim($input->attr('type') ?? 'text'));
            if (in_array($type, ['submit', 'button'], true)) {
                return;
            }
            $payload[$name] = $input->attr('value') ?? '';
        });

        $triggerName = trim($node->attr('name') ?? '');
        if ($triggerName !== '') {
            $payload[$triggerName] = $node->attr('value') ?? '';
        }

        return ['action' => $action, 'method' => $method, 'payload' => $payload];
    }

    /**
     * @return array{action:string,method:string,payload:array<string,string>}|null
     */
    private function extractOnclickStep(Crawler $node, string $baseUrl): ?array
    {
        $onclick    = trim($node->attr('onclick') ?? '');
        $dataAction = trim($node->attr('data-action') ?? '');

        $patterns = [
            ['pattern' => '/\$\.post\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*(\{[^}]*\}))?/s', 'method' => 'POST'],
            ['pattern' => '/\$\.get\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*(\{[^}]*\}))?/s', 'method' => 'GET'],
            ['pattern' => '/\$\.ajax\s*\(\s*\{[^}]*[\'"]?url[\'"]?\s*:\s*[\'"]([^\'"]+)[\'"]/is', 'method' => 'POST'],
            ['pattern' => '/fetch\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'method' => 'POST'],
            ['pattern' => '/axios\.post\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'method' => 'POST'],
            ['pattern' => '/axios\.get\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'method' => 'GET'],
            ['pattern' => '/(?:window\.)?location(?:\.href)?\s*=\s*[\'"]([^\'"]+)[\'"]/i', 'method' => 'GET'],
            ['pattern' => '/window\.open\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'method' => 'GET'],
        ];

        foreach ($patterns as ['pattern' => $pattern, 'method' => $method]) {
            if (preg_match($pattern, $onclick, $m)) {
                $url = $this->toAbsolute(trim($m[1]), $baseUrl);
                if ($url !== null) {
                    $payload = isset($m[2]) ? $this->parseJsObject($m[2]) : [];

                    return ['action' => $url, 'method' => $method, 'payload' => $payload];
                }
            }
        }

        if ($dataAction !== '') {
            $url = $this->toAbsolute($dataAction, $baseUrl);
            if ($url !== null) {
                return ['action' => $url, 'method' => 'POST', 'payload' => []];
            }
        }

        return null;
    }

    private function extractDataUrl(Crawler $node, string $baseUrl): ?string
    {
        foreach (['data-url', 'data-href', 'data-link', 'data-src', 'href'] as $attr) {
            $val = trim($node->attr($attr) ?? '');
            if ($val === '' || str_starts_with($val, '#') || str_starts_with($val, 'javascript:')) {
                continue;
            }
            $url = $this->toAbsolute($val, $baseUrl);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function extractFinalUrl(string $html, string $currentUrl): ?string
    {
        if ($this->isDownloadUrl($currentUrl)) {
            return $currentUrl;
        }

        $crawler = new Crawler($html, $currentUrl);

        foreach ($crawler->filter('a[href]') as $a) {
            $href = trim($a->getAttribute('href') ?? '');
            if ($href !== '' && $this->isDownloadUrl($href)) {
                $abs = $this->toAbsolute($href, $currentUrl);
                if ($abs !== null) {
                    return $abs;
                }
            }
        }

        foreach ($crawler->filter('meta[http-equiv]') as $meta) {
            if (strtolower($meta->getAttribute('http-equiv') ?? '') !== 'refresh') {
                continue;
            }
            $content = $meta->getAttribute('content') ?? '';
            if (preg_match('/url\s*=\s*[\'"]?([^\'";\s>]+)/i', $content, $m)) {
                $url = $this->toAbsolute(trim($m[1]), $currentUrl);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        foreach ($crawler->filter('script:not([src])') as $script) {
            $found = $this->urlFromJs($script->textContent ?? '', $currentUrl);
            if ($found !== null) {
                return $found;
            }
        }

        return $this->urlFromJs($html, $currentUrl);
    }

    private function urlFromJs(string $text, string $baseUrl): ?string
    {
        $patterns = [
            '/(https?:\/\/[^\s"\'<>]+\.(?:mp4|m3u8|mkv|avi|mov|webm|ts)(?:[?#][^\s"\'<>]*)?)/i',
            '/(?:file|url|src|source|video|stream|download)\s*[:=]\s*["\']([^"\']+\.(?:mp4|m3u8|mkv|avi|mov|webm|ts)[^"\']*)["\']["\']?/i',
            '/(https?:\/\/[^\s"\'<>]*\/(?:download|stream|video)\/[^\s"\'<>]*)/i',
            '/(https?:\/\/[^\s"\'<>]*[?&](?:token|signature|sig|key)=[^\s"\'<>]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $url = trim($m[1]);
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function isDownloadUrl(string $url): bool
    {
        $lower = strtolower($url);

        foreach (self::DOWNLOAD_EXTENSIONS as $ext) {
            if (preg_match('/\.' . preg_quote($ext, '/') . '([?#]|$)/i', $lower)) {
                return true;
            }
        }

        return str_contains($lower, '/download/') || str_contains($lower, '/stream/');
    }

    /**
     * @param array{action:string,method:string,payload:array<string,string>} $step
     */
    private function submitStep(array $step, string $referer, CookieJar $jar): \Illuminate\Http\Client\Response
    {
        $client = $this->client($referer, $jar);

        return $step['method'] === 'POST'
            ? $client->asForm()->post($step['action'], $step['payload'])->throw()
            : $client->get($step['action'], $step['payload'])->throw();
    }

    /**
     * Client HTTP standard (suit les redirects).
     */
    private function client(string $referer, CookieJar $jar): PendingRequest
    {
        return $this->http
            ->withHeaders([
                'User-Agent'                => self::USER_AGENT,
                'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language'           => 'ar,fr-FR;q=0.9,en-US;q=0.8',
                'Referer'                   => $referer,
                'Upgrade-Insecure-Requests' => '1',
            ])
            ->withOptions([
                'cookies'         => $jar,
                'allow_redirects' => ['max' => 10, 'track_redirects' => true],
                'verify'          => false,
                'decode_content'  => false,
                'curl'            => [CURLOPT_ENCODING => 'gzip, deflate'],
            ])
            ->timeout((int) config('scraper.http_timeout', 20));
    }

    /**
     * Client HTTP qui NE suit PAS les redirects (pour capturer Location header).
     */
    private function clientNoRedirect(string $referer, CookieJar $jar): PendingRequest
    {
        return $this->http
            ->withHeaders([
                'User-Agent'                => self::USER_AGENT,
                'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language'           => 'ar,fr-FR;q=0.9,en-US;q=0.8',
                'Referer'                   => $referer,
                'Upgrade-Insecure-Requests' => '1',
            ])
            ->withOptions([
                'cookies'         => $jar,
                'allow_redirects' => false,
                'verify'          => false,
                'decode_content'  => false,
                'curl'            => [CURLOPT_ENCODING => 'gzip, deflate'],
            ])
            ->timeout((int) config('scraper.http_timeout', 20));
    }

    private function toAbsolute(string $url, string $base): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}:{$url}";
        }

        if (! str_starts_with($url, '/')) {
            return null;
        }

        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port   = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$scheme}://{$parts['host']}{$port}{$url}";
    }

    /**
     * @return array<string,string>
     */
    private function parseJsObject(string $jsObj): array
    {
        $result = [];
        preg_match_all('/(\w+)\s*:\s*(?:[\'"]([^\'"]*)[\'"]|(\d+))/', $jsObj, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $result[$match[1]] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        }

        return $result;
    }

    public function summarizeHtml(string $html): array
    {
        if (trim($html) === '') {
            return ['result_title' => null, 'result_h1' => null, 'result_preview' => ''];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        return [
            'result_title'   => ($t = $dom->getElementsByTagName('title')->item(0)) ? trim($t->textContent) : null,
            'result_h1'      => ($h = $dom->getElementsByTagName('h1')->item(0)) ? trim($h->textContent) : null,
            'result_preview' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''), 0, 400),
        ];
    }
}
