<?php

namespace App\Services\Scraper;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class BrowserClickService
{
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
        $host    = parse_url($iframeUrl, PHP_URL_HOST) ?? '';

        Log::info('BrowserClick: début résolution.', [
            'iframe_url' => $iframeUrl,
            'referer'    => $referer,
            'host'       => $host,
        ]);

        try {
            // ── Étape 1 : charger la page embed ──────────────────────────────────────────
            $response   = $this->client($referer, $jar)->get($iframeUrl)->throw();
            $currentUrl = (string) ($response->effectiveUri() ?? $iframeUrl);
            $html       = $response->body();
            $htmlLen    = mb_strlen($html);

            Log::info('BrowserClick: page embed chargée.', [
                'current_url'  => $currentUrl,
                'html_length'  => $htmlLen,
                'has_eval'     => str_contains($html, 'eval(function(p,a,c,k,e,d)'),
                'has_jwplayer' => str_contains($html, 'jwplayer'),
                'has_method_free' => str_contains($html, 'method_free'),
            ]);

            // ── Tentative rapide : URL déjà dans le HTML initial (avant tout POST) ───────
            $earlyScript = $this->extractJwPlayerScriptUrl($html);
            if ($earlyScript !== null) {
                Log::info('BrowserClick: URL extraite depuis HTML initial (eval, sans POST).', [
                    'final_url' => $earlyScript,
                    'strategy'  => 'eval_initial',
                ]);

                return $this->verifyAndReturn($earlyScript, $iframeUrl, $html, 'eval_initial');
            }

            // ── Étape 2 : POST #method_free pour débloquer la page vidéo ─────────────────
            // vdesk requiert ce POST pour servir le HTML complet (~19 740 o) avec l'URL S3.
            Log::info('BrowserClick: tentative POST method_free.', ['current_url' => $currentUrl]);
            [$html, $currentUrl] = $this->simulateClick($html, $currentUrl, 'method_free', $jar);

            $htmlLen = mb_strlen($html);

            Log::info('BrowserClick: après method_free.', [
                'current_url'     => $currentUrl,
                'html_length'     => $htmlLen,
                'has_downloadbtn' => str_contains($html, 'downloadbtn'),
                'has_eval'        => str_contains($html, 'eval(function(p,a,c,k,e,d)'),
            ]);

            // Si la réponse est trop petite, vdesk a renvoyé une page de rate-limit.
            // On retente jusqu'à 3 fois avec délai croissant.
            if ($htmlLen < self::VDESK_MIN_VALID_HTML) {
                $retryDelays = [5_000_000, 10_000_000, 15_000_000]; // 5s, 10s, 15s
                $retried = false;

                foreach ($retryDelays as $attempt => $delay) {
                    $delaySec = $delay / 1_000_000;
                    Log::warning("BrowserClick: HTML trop petit ({$htmlLen} o < " . self::VDESK_MIN_VALID_HTML . ' o), retry #' . ($attempt + 1) . " dans {$delaySec}s.", [
                        'html_length' => $htmlLen,
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
                        'has_eval'    => str_contains($html, 'eval(function(p,a,c,k,e,d)'),
                    ]);

                    if ($htmlLen >= self::VDESK_MIN_VALID_HTML) {
                        $retried = true;
                        break;
                    }
                }

                if (! $retried) {
                    Log::warning('BrowserClick: rate-limit persistant après ' . count($retryDelays) . ' tentatives.', [
                        'html_length' => $htmlLen,
                    ]);
                }
            }

            // ── Stratégie A : script JWPlayer eval(function(p,a,c,k,e,d){...}) ──────────
            $jwScriptUrl = $this->extractJwPlayerScriptUrl($html);
            if ($jwScriptUrl !== null) {
                Log::info('BrowserClick: URL extraite depuis script JWPlayer eval() (post method_free).', [
                    'final_url' => $jwScriptUrl,
                    'strategy'  => 'eval_post',
                ]);

                return $this->verifyAndReturn($jwScriptUrl, $iframeUrl, $html, 'eval_post');
            }

            // ── Stratégie B : balise <video class="jw-video" src="..."> ─────────────────
            $jwVideoSrc = $this->extractJwVideoSrc($html);
            if ($jwVideoSrc !== null) {
                Log::info('BrowserClick: URL extraite depuis <video class="jw-video">.', [
                    'final_url' => $jwVideoSrc,
                    'strategy'  => 'jw_video_tag',
                ]);

                return $this->verifyAndReturn($jwVideoSrc, $iframeUrl, $html, 'jw_video_tag');
            }

            // ── Stratégie C : recherche générique dans le HTML ───────────────────────────
            $earlyUrl = $this->extractFinalUrl($html, $currentUrl);
            if ($earlyUrl !== null) {
                Log::info('BrowserClick: URL trouvée via extractFinalUrl.', [
                    'final_url' => $earlyUrl,
                    'strategy'  => 'generic_html',
                ]);

                return $this->verifyAndReturn($earlyUrl, $iframeUrl, $html, 'generic_html');
            }

            // ── Stratégie D : formulaire #downloadbtn (redirect 302 → URL finale) ───────
            usleep(800_000);

            $finalUrl = $this->submitFinalStep($html, $currentUrl, 'downloadbtn', $jar);
            if ($finalUrl !== null) {
                Log::info('BrowserClick: URL trouvée via redirect #downloadbtn.', [
                    'final_url' => $finalUrl,
                    'strategy'  => 'downloadbtn_redirect',
                ]);

                return $this->verifyAndReturn($finalUrl, $iframeUrl, $html, 'downloadbtn_redirect');
            }

            // ── Aucune stratégie n'a fonctionné ──────────────────────────────────────────
            Log::warning('BrowserClick: aucune URL finale détectée après toutes les stratégies.', [
                'current_url' => $currentUrl,
                'strategies_tried' => ['eval_initial', 'eval_post', 'jw_video_tag', 'generic_html', 'downloadbtn_redirect'],
            ]);

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
     * Vérifie l'URL vidéo avec un HEAD request et retourne le résultat formaté.
     *
     * @return array{success:bool,final_url:?string,final_html:?string,error:?string}
     */
    private function verifyAndReturn(string $url, string $iframeUrl, string $html, string $strategy): array
    {
        // Les CDN HLS (vidspeed, vidoba) utilisent des tokens à usage unique.
        // Un HEAD/GET "consomme" le token → l'URL ne marchera plus ensuite.
        // On ne vérifie par HTTP QUE les URLs sans token (ex: vdesk MP4 direct).
        $hasToken = str_contains($url, '?t=') || str_contains($url, '&t=') || str_contains($url, '?token=');
        $isHls    = str_contains($url, '.m3u8');

        if ($hasToken || $isHls) {
            // Vérification structurelle uniquement (pas de requête HTTP)
            $structureOk = filter_var($url, FILTER_VALIDATE_URL)
                && preg_match('/\.(mp4|m3u8|mkv|webm|ts)(\?|$)/i', $url);

            if ($structureOk) {
                Log::info('BrowserClick: ✅ URL HLS/token acceptée (vérification structurelle, pas de HEAD).', [
                    'final_url' => $url,
                    'strategy'  => $strategy,
                    'is_hls'    => $isHls,
                ]);

                return ['success' => true, 'final_url' => $url, 'final_html' => $html, 'error' => null];
            }

            Log::warning('BrowserClick: ❌ URL structurellement invalide.', [
                'rejected_url' => $url,
                'strategy'     => $strategy,
            ]);

            return ['success' => false, 'final_url' => null, 'final_html' => $html, 'error' => "URL structurellement invalide: {$url}"];
        }

        // URLs sans token (ex: vdesk MP4) → vérification HTTP complète
        $verification = $this->verifyVideoUrl($url, $iframeUrl);

        if ($verification['accessible']) {
            Log::info('BrowserClick: ✅ URL vidéo vérifiée et accessible.', [
                'final_url'    => $url,
                'strategy'     => $strategy,
                'http_status'  => $verification['status'],
                'content_type' => $verification['content_type'],
                'content_size' => $verification['content_length'],
            ]);

            return ['success' => true, 'final_url' => $url, 'final_html' => $html, 'error' => null];
        }

        Log::warning('BrowserClick: ❌ URL vidéo inaccessible (HTTP vérifié), ignorée.', [
            'rejected_url' => $url,
            'strategy'     => $strategy,
            'http_status'  => $verification['status'],
            'content_type' => $verification['content_type'],
            'error'        => $verification['error'],
        ]);

        return ['success' => false, 'final_url' => null, 'final_html' => $html, 'error' => "URL inaccessible (HTTP {$verification['status']}): {$url}"];
    }

    /**
     * Effectue un HEAD request pour vérifier qu'une URL vidéo est accessible.
     *
     * @return array{accessible:bool,status:int,content_type:string,content_length:?string,error:?string}
     */
    public function verifyVideoUrl(string $url, ?string $referer = null): array
    {
        // Le CDN HLS exige un referer du domaine du player (vidspeed.org, vidoba.org)
        // On essaie d'abord le referer fourni, puis le domaine de l'iframe embed.
        $urlParts = parse_url($url);
        $embedReferer = $referer ?? $url;

        // Construire les referers à tester (le spécifique d'abord, puis l'origin du CDN)
        $referersToTry = [$embedReferer];

        // Ajouter l'origin du referer embed comme fallback
        if ($referer !== null) {
            $refParts = parse_url($referer);
            if (isset($refParts['host'])) {
                $refOrigin = ($refParts['scheme'] ?? 'https') . '://' . $refParts['host'] . '/';
                if (! in_array($refOrigin, $referersToTry, true)) {
                    $referersToTry[] = $refOrigin;
                }
            }
        }

        foreach ($referersToTry as $tryReferer) {
            $result = $this->doVerifyRequest($url, $tryReferer);

            if ($result['accessible']) {
                return $result;
            }

            // Si 403, retenter avec le prochain referer
            if ($result['status'] !== 403) {
                return $result; // Autre erreur (404, timeout...) → pas la peine de retenter
            }

            Log::info('BrowserClick: vérification 403 avec referer, retry avec un autre.', [
                'url'          => mb_substr($url, 0, 80),
                'tried_referer' => $tryReferer,
            ]);
        }

        // Tous les referers ont échoué → retourner le dernier résultat
        return $result ?? [
            'accessible'     => false,
            'status'         => 0,
            'content_type'   => '',
            'content_length' => null,
            'error'          => 'Tous les referers ont échoué',
        ];
    }

    /**
     * @return array{accessible:bool,status:int,content_type:string,content_length:?string,error:?string}
     */
    private function doVerifyRequest(string $url, string $referer): array
    {
        $headers = ScraperSecurityService::realisticHeaders($referer);
        $headers['Range'] = 'bytes=0-0';
        $headers['Origin'] = preg_replace('#^(https?://[^/]+).*#', '$1', $referer);

        $options = [
            'verify'          => false,
            'allow_redirects' => ['max' => 5, 'track_redirects' => true],
        ];

        // Ajouter le proxy si configuré
        $proxy = ScraperSecurityService::proxyConfig();
        if ($proxy !== null) {
            $proxyUrl = "http://{$proxy['host']}:{$proxy['port']}";
            if ($proxy['auth'] !== null) {
                $proxyUrl = "http://{$proxy['auth']}@{$proxy['host']}:{$proxy['port']}";
            }
            $options['proxy'] = $proxyUrl;
        }

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->withOptions($options)
                ->timeout(10)
                ->head($url);

            $status      = $response->status();
            $contentType = $response->header('Content-Type') ?? '';

            // Si HEAD retourne 405 (Method Not Allowed), retenter avec GET + Range
            if ($status === 405) {
                $response = $this->http
                    ->withHeaders($headers)
                    ->withOptions($options)
                    ->timeout(10)
                    ->get($url);

                $status      = $response->status();
                $contentType = $response->header('Content-Type') ?? '';
            }

            $accessible = in_array($status, [200, 206, 302, 301], true);

            return [
                'accessible'     => $accessible,
                'status'         => $status,
                'content_type'   => $contentType,
                'content_length' => $response->header('Content-Length'),
                'error'          => $accessible ? null : "HTTP {$status}",
            ];
        } catch (\Throwable $e) {
            return [
                'accessible'     => false,
                'status'         => 0,
                'content_type'   => '',
                'content_length' => null,
                'error'          => $e->getMessage(),
            ];
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

        // Parser les 4 arguments en gérant les quotes échappées (\'  \")
        $parsedArgs = $this->parsePackerArgs($innerContent);
        if ($parsedArgs === null) {
            return null;
        }

        $packed = $parsedArgs['packed'];
        $radix  = $parsedArgs['radix'];
        $keys   = $parsedArgs['keys'];

        // Décode le packed string : chaque token \bWORD\b est remplacé par keys[base_decode(token)]
        // En JS : if(k[c]) signifie "ne remplacer que si la clé est non-vide (truthy)".
        // Si k[c] est '' (vide), le token garde sa valeur numérique originale.
        $decoded = preg_replace_callback(
            '/\b([0-9a-zA-Z]+)\b/',
            static function (array $m) use ($keys, $radix): string {
                $index = (int) base_convert($m[1], $radix, 10);
                $replacement = $keys[$index] ?? '';

                return $replacement !== '' ? $replacement : $m[1];
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
            // Délai aléatoire avant la requête (imiter comportement humain)
            ScraperSecurityService::randomDelay(800, 2000);

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
        // Délai aléatoire avant la requête (imiter comportement humain)
        ScraperSecurityService::randomDelay(600, 1500);

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
        $options = [
            'cookies'         => $jar,
            'allow_redirects' => ['max' => 10, 'track_redirects' => true],
            'verify'          => false,
            'decode_content'  => false,
            'curl'            => [CURLOPT_ENCODING => 'gzip, deflate'],
        ];

        // Ajouter le proxy si configuré
        $proxy = ScraperSecurityService::proxyConfig();
        if ($proxy !== null) {
            $proxyUrl = "http://{$proxy['host']}:{$proxy['port']}";
            if ($proxy['auth'] !== null) {
                $proxyUrl = "http://{$proxy['auth']}@{$proxy['host']}:{$proxy['port']}";
            }
            $options['proxy'] = $proxyUrl;
        }

        return $this->http
            ->withHeaders(ScraperSecurityService::realisticHeaders($referer))
            ->withOptions($options)
            ->timeout((int) config('scraper.http_timeout', 20));
    }

    /**
     * Client HTTP qui NE suit PAS les redirects (pour capturer Location header).
     */
    private function clientNoRedirect(string $referer, CookieJar $jar): PendingRequest
    {
        $options = [
            'cookies'         => $jar,
            'allow_redirects' => false,
            'verify'          => false,
            'decode_content'  => false,
            'curl'            => [CURLOPT_ENCODING => 'gzip, deflate'],
        ];

        // Ajouter le proxy si configuré
        $proxy = ScraperSecurityService::proxyConfig();
        if ($proxy !== null) {
            $proxyUrl = "http://{$proxy['host']}:{$proxy['port']}";
            if ($proxy['auth'] !== null) {
                $proxyUrl = "http://{$proxy['auth']}@{$proxy['host']}:{$proxy['port']}";
            }
            $options['proxy'] = $proxyUrl;
        }

        return $this->http
            ->withHeaders(ScraperSecurityService::realisticHeaders($referer))
            ->withOptions($options)
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
    /**
     * Parse les 4 arguments du JS packer : 'packed',RADIX,COUNT,'keys'[.split('|')]
     *
     * Gère les quotes échappées (\' \") dans la packed string,
     * ce que les regex simples ne peuvent pas faire.
     *
     * @return array{packed:string,radix:int,keys:string[]}|null
     */
    private function parsePackerArgs(string $content): ?array
    {
        $len = strlen($content);
        $i = 0;

        // Skip whitespace
        while ($i < $len && ctype_space($content[$i])) {
            $i++;
        }

        // 1) Extraire la packed string (première string entre quotes, avec escapes)
        $packed = $this->extractJsString($content, $i);
        if ($packed === null) {
            return null;
        }
        $i = $packed['end'];

        // Skip ,
        $i = $this->skipChars($content, $i, $len, ', ');

        // 2) Extraire radix (nombre)
        $radixStr = '';
        while ($i < $len && ctype_digit($content[$i])) {
            $radixStr .= $content[$i++];
        }
        if ($radixStr === '') {
            return null;
        }

        // Skip ,
        $i = $this->skipChars($content, $i, $len, ', ');

        // 3) Extraire count (nombre)
        $countStr = '';
        while ($i < $len && ctype_digit($content[$i])) {
            $countStr .= $content[$i++];
        }

        // Skip ,
        $i = $this->skipChars($content, $i, $len, ', ');

        // 4) Extraire la keys string (dernière string entre quotes)
        $keysResult = $this->extractJsString($content, $i);
        if ($keysResult === null) {
            return null;
        }

        return [
            'packed' => $packed['value'],
            'radix' => (int) $radixStr,
            'keys' => explode('|', $keysResult['value']),
        ];
    }

    /**
     * Extrait une string JS depuis une position donnée, en gérant les escapes (\' \").
     *
     * @return array{value:string,end:int}|null
     */
    private function extractJsString(string $text, int $pos): ?array
    {
        $len = strlen($text);
        if ($pos >= $len) {
            return null;
        }

        $quote = $text[$pos];
        if ($quote !== "'" && $quote !== '"') {
            return null;
        }

        $value = '';
        $i = $pos + 1;
        while ($i < $len) {
            if ($text[$i] === '\\' && $i + 1 < $len) {
                $value .= $text[$i] . $text[$i + 1];
                $i += 2;

                continue;
            }
            if ($text[$i] === $quote) {
                return ['value' => $value, 'end' => $i + 1];
            }
            $value .= $text[$i];
            $i++;
        }

        return null;
    }

    private function skipChars(string $text, int $pos, int $len, string $chars): int
    {
        while ($pos < $len && str_contains($chars, $text[$pos])) {
            $pos++;
        }

        return $pos;
    }

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
