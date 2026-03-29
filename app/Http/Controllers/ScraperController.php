<?php

namespace App\Http\Controllers;

use App\Models\Series;
use App\Services\Scraper\ExternalSeriesScraperService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScraperController extends Controller
{
    private const CACHE_KEY = 'external_series_data';
    private const CACHE_DURATION = 24 * 60 * 60; // 24 heures

    /**
     * Affiche la page du scraper (Vue.js)
     */
    public function index(): View
    {
        // La Vue.js app gère tout le chargement de données via les API endpoints
        // Pas besoin de passer de données initiales
        return view('scraper_vue');
    }

    /**
     * API Endpoint: Scrape les séries et retourne le JSON
     */
    public function scrape(ExternalSeriesScraperService $scraperService): JsonResponse
    {
        try {
            // Vérifier s'il y a déjà des données en cache
            $cachedData = Cache::get(self::CACHE_KEY);
            if ($cachedData) {
                Log::info('Données retournées depuis le cache');
                return response()->json([
                    'success' => true,
                    'series' => $cachedData,
                    'total' => count($cachedData),
                    'source' => 'cache',
                ]);
            }

            Log::info('Démarrage du scraping');

            // Scraper les données
            $scrapedSeries = $scraperService->scrapeAllSeries(function (int $percent, string $message) {
                Log::info('Progression scraping', ['percent' => $percent, 'message' => $message]);
            });

            // Sauvegarder en cache immédiatement
            Cache::put(self::CACHE_KEY, $scrapedSeries, self::CACHE_DURATION);
            Cache::put(self::CACHE_KEY . '_expires_at', now()->addSeconds(self::CACHE_DURATION), self::CACHE_DURATION);

            // Sauvegarder en base de données dans une transaction
            DB::transaction(function () use ($scrapedSeries) {
                Log::info('Début de la sauvegarde en base de données', ['count' => count($scrapedSeries)]);

                // Marquer tous les séries actifs comme "en vérification"
                Series::where('source', Series::SOURCE_ESHEAQ)
                    ->where('status', Series::STATUS_ACTIVE)
                    ->update(['status' => 'checking']);

                $inserted = 0;
                $updated = 0;

                foreach ($scrapedSeries as $seriesData) {
                    try {
                        // Trouver ou créer la série
                        $series = Series::firstOrCreate(
                            ['titre' => $seriesData['titre'], 'url' => $seriesData['url']],
                            [
                                'image' => $seriesData['image'],
                                'source' => Series::SOURCE_ESHEAQ,
                                'status' => Series::STATUS_ACTIVE,
                                'last_scraped_at' => now(),
                            ]
                        );

                        // Mettre à jour les données
                        if ($series->wasRecentlyCreated) {
                            $inserted++;
                        } else {
                            $series->update([
                                'image' => $seriesData['image'],
                                'status' => Series::STATUS_ACTIVE,
                                'last_scraped_at' => now(),
                            ]);
                            $updated++;
                        }
                    } catch (\Exception $e) {
                        Log::warning('Erreur sauvegarde série', [
                            'titre' => $seriesData['titre'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Marquer les séries non trouvés comme inactifs
                Series::where('source', Series::SOURCE_ESHEAQ)
                    ->where('status', 'checking')
                    ->update(['status' => Series::STATUS_INACTIVE]);

                Log::info('Sauvegarde en base de données complétée', [
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'total' => count($scrapedSeries),
                ]);
            });

            Log::info('Scraping complété et mis en cache', ['total' => count($scrapedSeries)]);

            return response()->json([
                'success' => true,
                'series' => $scrapedSeries,
                'total' => count($scrapedSeries),
                'source' => 'scraped',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur scraping', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Erreur lors du scraping des séries',
            ], 500);
        }
    }

    /**
     * Vider le cache
     */
    public function clearCache(): JsonResponse
    {
        try {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::CACHE_KEY . '_expires_at');

            Log::info('Cache supprimé');

            return response()->json([
                'success' => true,
                'message' => 'Cache supprimé avec succès',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression cache', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
