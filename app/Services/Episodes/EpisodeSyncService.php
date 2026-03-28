<?php

namespace App\Services\Episodes;

use App\Jobs\RunScrapeEpisodesJob;
use App\Models\Episode;
use App\Models\SeriesInfo;
use App\Services\Scraper\EpisodeListScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EpisodeSyncService
{
    public function __construct(private readonly EpisodeListScraper $episodeListScraper)
    {
    }

    /**
     * @return array{status:string,message:string,series_total:int,series_processed:int,new_episodes_count:int,errors:array<int,string>}
     */
    public function syncAllSeries(string $trigger): array
    {
        $lock = Cache::lock('episodes:sync-all', 3600);

        if (! $lock->get()) {
            return [
                'status' => 'busy',
                'message' => 'Une synchronisation est déjà en cours.',
                'series_total' => 0,
                'series_processed' => 0,
                'new_episodes_count' => 0,
                'errors' => [],
            ];
        }

        $seriesProcessed = 0;
        $newEpisodesCount = 0;
        $errors = [];
        $seriesInfos = SeriesInfo::query()
            ->whereNotNull('series_page_url')
            ->where('series_page_url', '!=', '')
            ->orderBy('id')
            ->get(['id', 'title', 'series_page_url']);

        try {
            foreach ($seriesInfos as $seriesInfo) {
                $seriesProcessed++;

                try {
                    $newForSeries = $this->syncSeriesNewEpisodes($seriesInfo);
                    $newEpisodesCount += $newForSeries;
                } catch (\Throwable $exception) {
                    $errors[] = sprintf('Échec sync série "%s" (#%d): %s', $seriesInfo->title ?: 'Sans titre', $seriesInfo->id, $exception->getMessage());
                }
            }

            $status = $errors === [] ? 'completed' : 'completed_with_errors';

            return [
                'status' => $status,
                'message' => $errors === []
                    ? sprintf('Synchronisation terminée: %d nouvelle(s) épisode(s) importé(s).', $newEpisodesCount)
                    : sprintf('Synchronisation terminée avec erreurs: %d nouvelle(s) épisode(s) importé(s).', $newEpisodesCount),
                'series_total' => $seriesInfos->count(),
                'series_processed' => $seriesProcessed,
                'new_episodes_count' => $newEpisodesCount,
                'errors' => $errors,
            ];
        } catch (\Throwable $exception) {
            Log::error('Erreur synchronisation globale des épisodes.', [
                'trigger' => $trigger,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Erreur pendant la synchronisation des épisodes.',
                'series_total' => $seriesInfos->count(),
                'series_processed' => $seriesProcessed,
                'new_episodes_count' => $newEpisodesCount,
                'errors' => [$exception->getMessage()],
            ];
        } finally {
            $lock->release();
        }
    }

    private function syncSeriesNewEpisodes(SeriesInfo $seriesInfo): int
    {
        $scrapedEpisodes = $this->episodeListScraper->scrape($seriesInfo->series_page_url);

        if ($scrapedEpisodes === []) {
            return 0;
        }

        // Une seule requête pour count + max(episode_number) ; une autre pour les URLs connues.
        $stats = Episode::query()
            ->where('series_info_id', $seriesInfo->id)
            ->selectRaw('COUNT(*) as total, MAX(episode_number) as max_number')
            ->first();

        $existingEpisodesCount    = (int) ($stats->total ?? 0);
        $latestStoredEpisodeNumber = $stats->max_number !== null ? (int) $stats->max_number : null;

        $alreadyKnownUrls = Episode::query()
            ->where('series_info_id', $seriesInfo->id)
            ->pluck('page_url')
            ->all();

        $knownUrlMap = array_fill_keys($alreadyKnownUrls, true);

        $newEpisodes = collect($scrapedEpisodes)
            ->filter(function (array $episode) use ($latestStoredEpisodeNumber, $knownUrlMap): bool {
                $pageUrl = trim((string) ($episode['page_url'] ?? ''));

                if ($pageUrl === '' || isset($knownUrlMap[$pageUrl])) {
                    return false;
                }

                $episodeNumber = $episode['episode_number'] ?? null;

                if (is_int($latestStoredEpisodeNumber) && is_int($episodeNumber)) {
                    return $episodeNumber > $latestStoredEpisodeNumber;
                }

                return true;
            })
            ->values();

        if ($newEpisodes->isEmpty()) {
            return 0;
        }

        // ── Logique du tag "nouveau" ─────────────────────────────────────────────────
        // Règle : marquer "nouveau" UNIQUEMENT si la série existait déjà (mise à jour)
        // ✅ Cas 1 : Série NEW (0 épisodes avant) → PAS de tag "nouveau"
        // ✅ Cas 2 : Série EXISTANTE (N épisodes avant) → tag "nouveau" pour les nouveaux
        // Cela permet de différencier :
        //   - Import initial : tous les épisodes sont normaux
        //   - Mise à jour    : seuls les nouveaux sont marqués "nouveau"
        $isSeriesUpdate = $existingEpisodesCount > 0;

        if ($isSeriesUpdate) {
            // Avant d'ajouter les nouveaux, réinitialiser le tag ancien sur les précédents
            // (pour éviter d'avoir plusieurs vagues de "nouveau" qui s'accumulent)
            Episode::query()
                ->where('series_info_id', $seriesInfo->id)
                ->where('is_new', true)
                ->update(['is_new' => false]);
        }

        $now = now();

        $insertPayload = $newEpisodes
            ->map(fn (array $episode): array => [
                'series_info_id' => $seriesInfo->id,
                'title' => (string) ($episode['title'] ?? ''),
                'page_url' => (string) $episode['page_url'],
                'episode_number' => $episode['episode_number'] ?? null,
                'image_url' => $episode['image_url'] ?? null,
                // STATUS_PENDING : l'épisode est découvert mais l'URL vidéo n'est pas encore
                // résolue. Le ScrapeEpisodesCommand le traitera lors du prochain scrape ou
                // via le bouton "Retry erreurs". Utiliser STATUS_DONE ici causait "Lien final
                // indisponible" car episodeQuery() exclut les épisodes done sans final_url.
                'status' => Episode::STATUS_PENDING,
                'is_new' => $isSeriesUpdate,  // tag "nouveau" seulement si mise à jour d'une série existante
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        $insertedCount = Episode::query()->insertOrIgnore($insertPayload);

        // ── Après insertion des nouveaux épisodes, dispatcher un job pour les scraper ──
        // Cela transforme les épisodes en pending (juste découverts) en épisodes done
        // (avec final_url). Sans cela, les épisodes resteraient "Récupération en cours..."
        // indéfiniment jusqu'à ce que l'utilisateur lance manuellement un scrape.
        if ($insertedCount > 0) {
            RunScrapeEpisodesJob::dispatch(
                '',                           // listPageUrl (vide : mode retry-only)
                '',                           // trackingKey (vide : pas de suivi UI)
                false,                        // retryErrors
                $seriesInfo->id               // seriesInfoId (scrape cette série uniquement)
            );

            Log::info('📤 Job de scraping dispatché après sync.', [
                'series_info_id' => $seriesInfo->id,
                'new_episodes_count' => $insertedCount,
            ]);
        }

        return $insertedCount;
    }
}
