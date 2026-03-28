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
     * Taille minimale (octets) d'une réponse vdesk considérée comme valide.
     * En dessous de ce seuil, c'est une page d'erreur/rate-limit (env. 13 056 o).
     */
    private const VDESK_MIN_VALID_HTML = 15_000;

    /**
     * @return array{success:bool,final_url:?string,final_html:?string,error:?string}
     */
    public function resolveDownloadUrl(string $iframeUrl, ?string $referer = null): array
    {
        $jar     = new CookieJar();
        $referer = $referer ?? $iframeUrl;

        Log::info('BrowserClick: début résolution.', ['iframe_url' => $iframeUrl, 'referer' => $referer]);

        try {
            // ── Étape 1 : charger la page embed ──────────────────────────────────────────
            $response   = $this->client($referer, $jar)->get($iframeUrl)->throw();
            $currentUrl = (string) ($response->effectiveUri() ?? $iframeUrl);
            $html       = $response->body();

            Log::info('BrowserClick: page initiale chargée.', [
                'current_url' => $currentUrl,
                'html_length' => mb_strlen($html),
            ]);

            // ── Tentative rapide : URL déjà dans le HTML initial (avant tout POST) ───────
            $earlyScript = $this->extractJwPlayerScriptUrl($html);
            if ($earlyScript !== null) {
                Log::info('BrowserClick: URL extraite depuis HTML initial (sans POST).', ['final_url' => $earlyScript]);

                return ['success' => true, 'final_url' => $earlyScript, 'final_html' => $html, 'error' => null];
            }

            // ── Étape 2 : POST #method_free pour débloquer la page vidéo ─────────────────
            // vdesk requiert ce POST pour servir le HTML complet (~19 740 o) avec l'URL S3.
            [$html, $currentUrl] = $this->simulateClick($html, $currentUrl, 'method_free', $jar);

            $htmlLen = mb_strlen($html);

            Log::info('BrowserClick: après method_free.', [
                'current_url'     => $currentUrl,
                'html_length'     => $htmlLen,
                'has_downloadbtn' => str_contains($html, 'downloadbtn'),
            ]);

            // Si la réponse est trop petite, vdesk a renvoyé une page de rate-limit.
            // On retente jusqu'à 3 fois avec délai croissant.
            if ($htmlLen < self::VDESK_MIN_VALID_HTML) {
                $retryDelays = [5_000_000, 10_000_000, 15_000_000]; // 5s, 10s, 15s
                $retried = false;

                foreach ($retryDelays as $attempt => $delay) {
                    Log::warning('BrowserClick: réponse trop petite (rate-limit ?), attente ' . ($delay / 1_000_000) . 's (tentative ' . ($attempt + 1) . ').', [
                        'html_length' => $htmlLen,
                        'threshold'   => self::VDESK_MIN_VALID_HTML,
                    ]);

                    usleep($delay);

                    // Nouvelle session (cookies propres)
                    $jarN = new CookieJar();
                    $responseN = $this->client($referer, $jarN)->get($iframeUrl)->throw();
                    $html       = $responseN->body();
                    $currentUrl = (string) ($responseN->effectiveUri() ?? $iframeUrl);
                    [$html, $currentUrl] = $this->simulateClick($html, $currentUrl, 'method_free', $jarN);
                    $htmlLen = mb_strlen($html);
                    $jar = $jarN;

                    Log::info('BrowserClick: après retry method_free #' . ($attempt + 1) . '.', [
                        'html_length' => $htmlLen,
                    ]);

                    if ($htmlLen >= self::VDESK_MIN_VALID_HTML) {
                        $retried = true;
                        break;
                    }
                }

                if (! $retried) {
                    Log::warning('BrowserClick: rate-limit persistant après 3 tentatives.');
                }
            }

            // ── Stratégie A : script JWPlayer eval(function(p,a,c,k,e,d){...}) ──────────
            // C'est la méthode la plus fiable : l'eval pack est dans le HTML serveur
            // et contient le paramètre `file:"https://s3.vdesk.live:8080/..."`.
            $jwScriptUrl = $this->extractJwPlayerScriptUrl($html);
            if ($jwScriptUrl !== null) {
                Log::info('BrowserClick: URL extraite depuis script JWPlayer eval().', ['final_url' => $jwScriptUrl]);

                return ['success' => true, 'final_url' => $jwScriptUrl, 'final_html' => $html, 'error' => null];
            }

            // ── Stratégie B : balise <video class="jw-video" src="..."> ─────────────────
            // Présent si JWPlayer écrit le tag vidéo directement côté serveur.
            $jwVideoSrc = $this->extractJwVideoSrc($html);
            if ($jwVideoSrc !== null) {
                Log::info('BrowserClick: URL extraite depuis <video class="jw-video">.', ['final_url' => $jwVideoSrc]);

                return ['success' => true, 'final_url' => $jwVideoSrc, 'final_html' => $html, 'error' => null];
            }

            // ── Stratégie C : recherche générique dans le HTML ───────────────────────────
            $earlyUrl = $this->extractFinalUrl($html, $currentUrl);
            if ($earlyUrl !== null) {
                Log::info('BrowserClick: URL finale dans HTML (extractFinalUrl).', ['final_url' => $earlyUrl]);

                return ['success' => true, 'final_url' => $earlyUrl, 'final_html' => $html, 'error' => null];
            }

            // ── Stratégie D : formulaire #downloadbtn (redirect 302 → URL finale) ───────
            usleep(800_000);

            $finalUrl = $this->submitFinalStep($html, $currentUrl, 'downloadbtn', $jar);
            if ($finalUrl !== null) {
                Log::info('BrowserClick: URL finale trouvée via redirect #downloadbtn.', ['final_url' => $finalUrl]);

                return ['success' => true, 'final_url' => $finalUrl, 'final_html' => $html, 'error' => null];
            }

            // ── Aucune stratégie n'a fonctionné ──────────────────────────────────────────
            Log::warning('BrowserClick: aucune URL finale détectée.', ['current_url' => $currentUrl]);

            return [
                'success'    => false,
                'final_url'  => null,
                'final_html' => $html,
                'error'      => 'Aucun lien de téléchargement détecté.',
            ];

        } catch (\Throwable $e) {
            Log::error('BrowserClick: erreur fatale.', ['iframe_url' => $iframeUrl, 'error' => $e->getMessage()]);

            return ['success' => false, 'final_url' => null, 'final_html' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extrait l'URL depuis la balise <video class="jw-video" src="...">.
     *
     * vdesk.live inclut directement le tag <video> avec l'URL MP4 finale dans
     * le HTML de la page embed — pas besoin de simuler des clics JS.
     */
    private function extractJwVideoSrc(string $html): ?string
    {
        // Recherche rapide : <video ... class="...jw-video..." ... src="URL">
        // On accepte n'importe quel ordre d'attributs.
        if (! str_contains($html, 'jw-video')) {
            return null;
        }

        // Regex qui trouve une balise <video> contenant la classe "jw-video"
        // et en extrait l'attribut src, quel que soit l'ordre des attributs.
        if (preg_match('/<video\b[^>]*\bclass=["\'][^"\']*\bjw-video\b[^"\']*["\'][^>]*\bsrc=["\'](https?:[^"\']+)["\'][^>]*>/i', $html, $m)) {
            return trim($m[1]) ?: null;
        }

        // Ordre inversé : src avant class
        if (preg_match('/<video\b[^>]*\bsrc=["\'](https?:[^"\']+)["\'][^>]*\bclass=["\'][^"\']*\bjw-video\b[^"\']*["\'][^>]*>/i', $html, $m)) {
            return trim($m[1]) ?: null;
        }

        // Fallback DOMDocument pour les pages avec attributs sur plusieurs lignes
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom->getElementsByTagName('video') as $video) {
            /** @var \DOMElement $video */
            $class = $video->getAttribute('class');
            if (str_contains($class, 'jw-video')) {
                $src = trim($video->getAttribute('src'));
                if ($src !== '' && filter_var($src, FILTER_VALIDATE_URL)) {
                    return $src;
                }
            }
        }

        return null;
    }

    /**
     * Décode le script JWPlayer obfusqué eval(function(p,a,c,k,e,d){...})
     * et extrait le paramètre "file" (URL MP4).
     *
     * Certaines pages vdesk encodent l'URL dans un pack JS au lieu de l'écrire
     * directement dans le tag <video>.
     */
    private function extractJwPlayerScriptUrl(string $html): ?string
    {
        // Repère le début du bloc eval(function(p,a,c,k,e,d){
        $marker = 'eval(function(p,a,c,k,e,d)';
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return null;
        }

        // Extraire le bloc complet en comptant les parenthèses depuis eval(
        $evalStart = $pos + 4; // position du '(' après 'eval'
        $evalBlock = $this->extractBalancedParens($html, $evalStart);
        if ($evalBlock === null) {
            return null;
        }

        // Trouver les args internes : }('packed',RADIX,COUNT,'keys'...)
        // On cherche la position de }( qui sépare le corps de la fonction des arguments
        $innerPos = strrpos($evalBlock, '}(');
        if ($innerPos === false) {
            return null;
        }
        $innerStart = $innerPos + 1; // position du '(' des args internes
        $innerArgs = $this->extractBalancedParens($evalBlock, $innerStart);
        if ($innerArgs === null) {
            return null;
        }

        // innerArgs = ('packed',36,345,'key1|key2|...'.split('|'))
        // Extraire : enlever ( au début et ) à la fin
        $innerContent = substr($innerArgs, 1, -1);

        // Extraire la keys string : dernière chaîne entre quotes
        // Peut se terminer par .split('|') ou pas
        $keysStr = null;
        if (preg_match("/,\s*['\"]([^'\"]*)['\"](?:\s*\.split\s*\([^)]*\))?\s*$/s", $innerContent, $km)) {
            $keysStr = $km[1];
        }
        if ($keysStr === null) {
            return null;
        }

        // Extraire radix et count : les 2 nombres avant la keys string
        // Format: 'packed',RADIX,COUNT,'keys'
        $keysPos = strrpos($innerContent, $keysStr);
        $beforeKeys = substr($innerContent, 0, $keysPos);
        if (! preg_match('/,\s*(\d+)\s*,\s*(\d+)\s*,\s*[\'"]?\s*$/s', $beforeKeys, $nm)) {
            return null;
        }
        $radix = (int) $nm[1];
        $count = (int) $nm[2];

        // Extraire le packed string : première string entre quotes
        $beforeNums = substr($beforeKeys, 0, -strlen($nm[0]));
        if (! preg_match("/^\s*['\"](.+)['\"]\s*$/s", $beforeNums, $pm)) {
            return null;
        }
        $packed = $pm[1];
        $keys   = explode('|', $keysStr);

        // Décode le packed string : chaque token \bWORD\b est remplacé par keys[base_decode(token)]
        $decoded = preg_replace_callback(
            '/\b([0-9a-zA-Z]+)\b/',
            static function (array $m) use ($keys, $radix): string {
                $index = base_convert($m[1], $radix, 10);

                return $keys[(int) $index] ?? $m[1];
            },
            $packed
        );

        if ($decoded === null) {
            return null;
        }

        // Cherche file:"URL" ou file:'URL' dans le script décodé
        if (preg_match('/\bfile\s*:\s*["\'](https?:[^"\']+\.(?:mp4|m3u8|mkv|webm|ts)[^"\']*)["\']/', $decoded, $f)) {
            return trim($f[1]);
        }

        // Fallback générique sur toute URL vidéo dans le script décodé
        if (preg_match('/(https?:\/\/[^\s"\'<>]+\.(?:mp4|m3u8|mkv|webm|ts)(?:[?#][^\s"\'<>]*)?)/i', $decoded, $f)) {
            return trim($f[1]);
        }

        return null;
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
            // Ignore les URLs malformées (ex : vdesk génère parfois "page.html]filename.mp4")
            if ($href !== '' && ! str_contains($href, ']') && $this->isDownloadUrl($href)) {
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
            '/(https?:\/\/[^\s"\'<>\[\]]+\.(?:mp4|m3u8|mkv|avi|mov|webm|ts)(?:[?#][^\s"\'<>\[\]]*)?)/i',
            '/(?:file|url|src|source|video|stream|download)\s*[:=]\s*["\']([^"\']+\.(?:mp4|m3u8|mkv|avi|mov|webm|ts)[^"\']*)["\']["\']?/i',
            '/(https?:\/\/[^\s"\'<>\[\]]*\/(?:download|stream|video)\/[^\s"\'<>\[\]]*)/i',
            '/(https?:\/\/[^\s"\'<>\[\]]*[?&](?:token|signature|sig|key)=[^\s"\'<>\[\]]+)/i',
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
     * Extrait le contenu entre parenthèses équilibrées à partir de la position donnée.
     * $pos doit pointer sur la parenthèse ouvrante '('.
     */
    private function extractBalancedParens(string $text, int $pos): ?string
    {
        $len   = strlen($text);
        if ($pos >= $len || $text[$pos] !== '(') {
            return null;
        }

        $depth  = 0;
        $inChar = null; // track si on est dans une string ' ou "

        for ($i = $pos; $i < $len; $i++) {
            $ch = $text[$i];

            // Gestion des strings (ignore les parens à l'intérieur)
            if ($inChar !== null) {
                if ($ch === '\\') {
                    $i++; // skip escaped char

                    continue;
                }
                if ($ch === $inChar) {
                    $inChar = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inChar = $ch;

                continue;
            }

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    // Retourne le contenu entre ( et ) inclus
                    return substr($text, $pos, $i - $pos + 1);
                }
            }
        }

        return null;
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
