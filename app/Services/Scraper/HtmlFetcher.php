<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;

class HtmlFetcher
{
    public function __construct(private readonly Factory $http) {}

    /**
     * Récupère le HTML d'une URL avec headers réalistes, proxy, et retry anti-blocage.
     */
    public function fetch(string $url): string
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Délai aléatoire entre les requêtes (sauf la première)
            if ($attempt > 1) {
                $backoff = ScraperSecurityService::exponentialBackoff($attempt, 3);
                Log::info("HtmlFetcher: retry #{$attempt} dans {$backoff}s.", ['url' => mb_substr($url, 0, 80)]);
                sleep($backoff);
            }

            $options = [
                'allow_redirects' => ['max' => 10],
                'verify'          => false,
                'decode_content'  => false,
                'curl'            => [CURLOPT_ENCODING => 'gzip, deflate'],
            ];

            // Couche 5 : Proxy
            $proxy = ScraperSecurityService::proxyConfig();
            if ($proxy !== null) {
                $proxyUrl = "http://{$proxy['host']}:{$proxy['port']}";
                if ($proxy['auth'] !== null) {
                    $proxyUrl = "http://{$proxy['auth']}@{$proxy['host']}:{$proxy['port']}";
                }
                $options['proxy'] = $proxyUrl;
            }

            $response = $this->http
                // Couches 1 + 2 + 3 : User-Agent rotatif, headers réalistes, referer aléatoire
                ->withHeaders(ScraperSecurityService::realisticHeaders())
                ->withOptions($options)
                ->timeout((int) config('scraper.http_timeout', 20))
                ->get($url);

            $status = $response->status();
            $body   = $response->body();

            // Couche 6 : Détection blocage et backoff
            if (ScraperSecurityService::isBlockingError($status)) {
                Log::warning("HtmlFetcher: blocage détecté (HTTP {$status}).", [
                    'url'     => mb_substr($url, 0, 80),
                    'attempt' => $attempt,
                ]);

                if ($attempt < $maxAttempts) {
                    continue; // Retenter avec backoff
                }

                // Dernier essai échoué → throw
                $response->throw();
            }

            // Couche 6 bis : Détection page de blocage (Cloudflare, anti-bot)
            if ($response->successful() && ScraperSecurityService::looksLikeBlockPage($body)) {
                Log::warning('HtmlFetcher: page de blocage détectée.', [
                    'url'         => mb_substr($url, 0, 80),
                    'attempt'     => $attempt,
                    'body_length' => mb_strlen($body),
                ]);

                if ($attempt < $maxAttempts) {
                    continue; // Retenter
                }
            }

            // Succès
            $response->throw();

            return $body;
        }

        // Fallback (ne devrait jamais arriver)
        return $this->http
            ->withHeaders(ScraperSecurityService::realisticHeaders())
            ->timeout((int) config('scraper.http_timeout', 20))
            ->get($url)
            ->throw()
            ->body();
    }
}
