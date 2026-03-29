<?php

namespace App\Services\Scraper;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ExternalSeriesScraperService
{
    private const BASE_URL = 'https://n.esheaq.onl';
    private const MAX_PAGES = 100;
    private const REQUEST_TIMEOUT = 30;
    private const DELAY_BETWEEN_REQUESTS = 500; // millisecondes
    private const REQUEST_RETRY_ATTEMPTS = 3;
    private const REQUEST_RETRY_DELAY = 2; // secondes

    /**
     * Scrape toutes les séries disponibles
     *
     * @return array<int, array{titre: string, url: string, image: string}>
     */
    public function scrapeAllSeries(callable $onProgress = null): array
    {
        try {
            Log::info('Début du scraping des séries externes');

            // Étape 1: Trouver l'URL de la page "جميع المسلسلات"
            $this->callProgress($onProgress, 0, 'Recherche de la page des séries...');
            $allSeriesUrl = $this->findAllSeriesPageUrl();

            if (!$allSeriesUrl) {
                throw new \Exception('Impossible de trouver la page "جميع المسلسلات"');
            }

            Log::info('Page trouvée', ['url' => $allSeriesUrl]);

            // Étape 2: Scraper toutes les pages
            $allSeries = [];
            $pageCount = 0;
            $currentUrl = $allSeriesUrl;

            while ($currentUrl && $pageCount < self::MAX_PAGES) {
                $pageCount++;
                $this->callProgress($onProgress, 10 + ($pageCount * 2), "Scraping page {$pageCount}...");

                $series = $this->scrapePage($currentUrl);
                Log::info("Page {$pageCount} scrapée", ['count' => count($series)]);

                foreach ($series as $item) {
                    if (!$this->seriesExists($allSeries, $item['url'])) {
                        $allSeries[] = $item;
                    }
                }

                // Attendre avant la prochaine requête (respectueux du serveur)
                usleep(self::DELAY_BETWEEN_REQUESTS * 1000);

                // Trouver le lien vers la page suivante
                $currentUrl = $this->findNextPageUrl($currentUrl);
            }

            $this->callProgress($onProgress, 95, 'Finalisation...');
            Log::info('Scraping complété', ['total_series' => count($allSeries), 'pages' => $pageCount]);

            return $allSeries;

        } catch (\Exception $e) {
            Log::error('Erreur scraping', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Scrape une seule page et retourne les séries trouvées
     *
     * @return array<int, array{titre: string, url: string, image: string}>
     */
    private function scrapePage(string $url): array
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                ->get($url);

            if (!$response->successful()) {
                Log::warning("Page non accessible: {$url}", ['status' => $response->status()]);
                return [];
            }

            $html = $response->body();
            $crawler = new Crawler($html);
            $series = [];

            // Chercher tous les articles (<article>)
            $crawler->filter('article')->each(function (Crawler $article) use (&$series) {
                try {
                    // Chercher le lien et l'image
                    $links = $article->filter('a');
                    $images = $article->filter('img');

                    if ($links->count() === 0 || $images->count() === 0) {
                        return;
                    }

                    $link = $links->first();
                    $image = $images->first();

                    $titre = $link->attr('title') ?: $link->text();
                    $seriesUrl = $link->attr('href');
                    // Essayer différents attributs pour l'image (data-image est utilisé sur ce site)
                    $imageUrl = $image->attr('data-image') ?: $image->attr('src') ?: $image->attr('data-src');

                    $titre = trim($titre);
                    $seriesUrl = trim($seriesUrl ?: '');
                    $imageUrl = trim($imageUrl ?: '');

                    // Valider les données
                    if ($this->isValidSeries($titre, $seriesUrl, $imageUrl)) {
                        // Construire URL absolue si nécessaire
                        if (!str_starts_with($seriesUrl, 'http')) {
                            $seriesUrl = rtrim(self::BASE_URL, '/') . '/' . ltrim($seriesUrl, '/');
                        }

                        $series[] = [
                            'titre' => $titre,
                            'url' => $seriesUrl,
                            'image' => $imageUrl,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::debug('Erreur parsing article', ['error' => $e->getMessage()]);
                }
            });

            return $series;

        } catch (\Exception $e) {
            Log::warning('Erreur lors du scraping de page', ['url' => $url, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Trouve l'URL de la page "جميع المسلسلات"
     */
    private function findAllSeriesPageUrl(): ?string
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                ->get(self::BASE_URL);

            if (!$response->successful()) {
                return null;
            }

            $html = $response->body();
            $crawler = new Crawler($html);

            // Chercher dans la navigation
            $pageUrl = null;

            $crawler->filter('nav a, .menu a, .nav a')->each(function (Crawler $link) use (&$pageUrl) {
                $text = $link->text();
                if (str_contains($text, 'جميع المسلسلات') || str_contains($text, 'المسلسلات')) {
                    $pageUrl = $link->attr('href');
                }
            });

            if ($pageUrl) {
                // Construire URL absolue si nécessaire
                if (!str_starts_with($pageUrl, 'http')) {
                    $pageUrl = rtrim(self::BASE_URL, '/') . '/' . ltrim($pageUrl, '/');
                }
                return $pageUrl;
            }

            return null;

        } catch (\Exception $e) {
            Log::warning('Erreur recherche page séries', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Trouve le lien vers la page suivante avec multiple selector strategies
     */
    private function findNextPageUrl(string $currentUrl): ?string
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                ->get($currentUrl);

            if (!$response->successful()) {
                Log::debug('Page non accessible pour recherche pagination', ['url' => $currentUrl, 'status' => $response->status()]);
                return null;
            }

            $html = $response->body();
            $crawler = new Crawler($html);
            $nextUrl = null;

            // Stratégie 1: Chercher rel="next" (sémantique HTML standard)
            try {
                $link = $crawler->filterXPath('//a[@rel="next"]');
                if ($link->count() > 0) {
                    $nextUrl = $link->first()->attr('href');
                    Log::debug('Lien pagination trouvé via rel="next"', ['url' => $nextUrl]);
                }
            } catch (\Exception $e) {
                Log::debug('Stratégie rel="next" échouée', ['error' => $e->getMessage()]);
            }

            // Stratégie 2: Chercher par classe de pagination
            if (!$nextUrl) {
                $crawler->filter('.pagination a, .nav-pagination a, .paging a, .page-link')->each(function (Crawler $link) use (&$nextUrl) {
                    if ($nextUrl) return; // déjà trouvé

                    $text = trim($link->text());
                    $ariaLabel = $link->attr('aria-label') ?? '';

                    // Chercher le lien "التالي" (suivant), ">", "Next", ou aria-label
                    if ($text === 'التالي' || $text === '>' || $text === 'Next' ||
                        str_contains($ariaLabel, 'التالي') || str_contains($ariaLabel, 'Next') ||
                        str_contains($text, 'التالي') || str_contains($text, 'Next')) {
                        $nextUrl = $link->attr('href');
                        Log::debug('Lien pagination trouvé via classe', ['text' => $text, 'url' => $nextUrl]);
                    }
                });
            }

            // Stratégie 3: Chercher par texte ou contenu du lien (plus flexible)
            if (!$nextUrl) {
                $crawler->filter('a')->each(function (Crawler $link) use (&$nextUrl) {
                    if ($nextUrl) return; // déjà trouvé

                    $text = trim($link->text());

                    // Chercher des variantes du "suivant" ou "next"
                    if (str_contains($text, 'التالي') || str_contains($text, 'التاليـة') ||
                        str_contains($text, 'next') || str_contains($text, 'Next') ||
                        ($text === '>' || $text === '→')) {
                        // Vérifier que ce n'est pas un lien "précédent"
                        if (!str_contains($text, 'السابق') && !str_contains($text, 'prev') && !str_contains($text, 'Prev') &&
                            !($text === '<' || $text === '←')) {
                            $nextUrl = $link->attr('href');
                            Log::debug('Lien pagination trouvé via contenu flexible', ['text' => $text, 'url' => $nextUrl]);
                        }
                    }
                });
            }

            // Construire URL absolue si nécessaire
            if ($nextUrl) {
                $nextUrl = trim($nextUrl);
                if (!empty($nextUrl) && !str_starts_with($nextUrl, 'http')) {
                    $nextUrl = rtrim(self::BASE_URL, '/') . '/' . ltrim($nextUrl, '/');
                }

                // Validation finale: vérifier que l'URL est différente
                if ($nextUrl && $nextUrl !== $currentUrl) {
                    return $nextUrl;
                }
            }

            Log::debug('Aucun lien pagination trouvé', ['url' => $currentUrl]);
            return null;

        } catch (\Exception $e) {
            Log::debug('Erreur recherche page suivante', ['url' => $currentUrl, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Vérifie si une série existe déjà dans la liste (évite les doublons)
     */
    private function seriesExists(array $series, string $url): bool
    {
        foreach ($series as $item) {
            if ($item['url'] === $url) {
                return true;
            }
        }
        return false;
    }

    /**
     * Appelle le callback de progression
     */
    private function callProgress(?callable $callback, int $percent, string $message): void
    {
        if ($callback && is_callable($callback)) {
            try {
                $callback($percent, $message);
            } catch (\Exception $e) {
                Log::debug('Erreur callback progress', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Valide les données d'une série
     */
    private function isValidSeries(string $titre, string $url, string $image): bool
    {
        // Valider le titre
        if (empty($titre) || strlen(trim($titre)) === 0) {
            return false;
        }

        // Valider l'URL
        if (empty($url) || strlen(trim($url)) === 0) {
            return false;
        }

        // Valider l'image
        if (empty($image) || strlen(trim($image)) === 0) {
            return false;
        }

        // Vérifier que l'URL est un format acceptable
        if (!str_starts_with($url, 'http') && !str_starts_with($url, '/')) {
            return false;
        }

        return true;
    }
}
