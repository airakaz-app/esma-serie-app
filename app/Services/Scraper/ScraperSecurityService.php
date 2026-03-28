<?php

namespace App\Services\Scraper;

class ScraperSecurityService
{
    /**
     * Liste des User-Agents réalistes pour rotation
     * (imiter différents navigateurs et systèmes)
     */
    private static array $userAgents = [
        // Chrome Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        // Chrome Mac
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        // Firefox Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0',
        // Firefox Mac
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0',
        // Safari Mac
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        // Edge Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
    ];

    /**
     * Referers réalistes pour rotation
     */
    private static array $referers = [
        'https://www.google.com/',
        'https://www.bing.com/',
        'https://duckduckgo.com/',
        'https://www.reddit.com/',
        'https://www.facebook.com/',
        '', // direct
    ];

    /**
     * Obtient un User-Agent aléatoire
     */
    public static function randomUserAgent(): string
    {
        return self::$userAgents[array_rand(self::$userAgents)];
    }

    /**
     * Obtient un referer aléatoire
     */
    public static function randomReferer(): string
    {
        return self::$referers[array_rand(self::$referers)];
    }

    /**
     * Génère un délai aléatoire entre min et max (en microsecondes)
     * Imite les délais humains entre les actions
     *
     * @param int $minMs Minimum en millisecondes
     * @param int $maxMs Maximum en millisecondes
     */
    public static function randomDelay(int $minMs = 500, int $maxMs = 2000): void
    {
        $microseconds = random_int($minMs * 1000, $maxMs * 1000);
        usleep($microseconds);
    }

    /**
     * Calcule un délai avec backoff exponentiel
     * Utilisé après un blocage (429, 403) pour retry plus tard
     *
     * @param int $attemptNumber Numéro de tentative (1, 2, 3...)
     * @param int $baseDelaySeconds Délai de base (ex: 5 secondes)
     * @return int Délai en secondes
     */
    public static function exponentialBackoff(int $attemptNumber, int $baseDelaySeconds = 5): int
    {
        // 2^n * base + jitter (ex: 5s, 10s, 20s, 40s...)
        $delay = $baseDelaySeconds * (2 ** ($attemptNumber - 1));
        // Ajouter du jitter (±20%)
        $jitter = (int) ($delay * 0.2);
        return $delay + random_int(-$jitter, $jitter);
    }

    /**
     * Génère des headers HTTP réalistes pour imiter un navigateur
     *
     * @param string|null $referer Referer spécifique (optionnel)
     * @return array<string, string>
     */
    public static function realisticHeaders(?string $referer = null): array
    {
        return [
            'User-Agent'      => self::randomUserAgent(),
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'DNT'             => '1',
            'Connection'      => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Referer'         => $referer ?? self::randomReferer(),
            'Sec-Fetch-Dest'  => 'document',
            'Sec-Fetch-Mode'  => 'navigate',
            'Sec-Fetch-Site'  => 'none',
            'Cache-Control'   => 'max-age=0',
        ];
    }

    /**
     * Obtient la configuration du proxy depuis l'env
     * Format: http://user:pass@host:port ou http://host:port
     *
     * @return array|null {'host': string, 'port': int, 'auth': string|null} ou null
     */
    public static function proxyConfig(): ?array
    {
        $proxyUrl = (string) env('SCRAPER_PROXY_URL', '');
        if ($proxyUrl === '') {
            return null;
        }

        $parsed = parse_url($proxyUrl);
        if (!isset($parsed['host'])) {
            return null;
        }

        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? 8080,
            'auth' => isset($parsed['user']) ? "{$parsed['user']}:{$parsed['pass']}" : null,
        ];
    }

    /**
     * Détermine si une erreur HTTP indique un blocage
     */
    public static function isBlockingError(int $statusCode): bool
    {
        return in_array($statusCode, [
            403, // Forbidden
            429, // Too Many Requests
            503, // Service Unavailable (anti-bot)
            401, // Unauthorized
        ], true);
    }

    /**
     * Détecte si une réponse HTML est une page d'erreur/blocage
     */
    public static function looksLikeBlockPage(string $html): bool
    {
        $patterns = [
            'cloudflare',
            'challenge',
            'robot',
            'bot',
            'automated',
            'scraper',
            'access denied',
            'blocked',
            'rate limit',
            'too many requests',
        ];

        $htmlLower = mb_strtolower($html);
        foreach ($patterns as $pattern) {
            if (str_contains($htmlLower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
