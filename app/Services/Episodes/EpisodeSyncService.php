<?php

namespace App\Services\Episodes;

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

        $existingEpisodesCount = Episode::query()
            ->where('series_info_id', $seriesInfo->id)
            ->count();

        $latestStoredEpisodeNumberRaw = Episode::query()
            ->where('series_info_id', $seriesInfo->id)
            ->max('episode_number');
        $latestStoredEpisodeNumber = $latestStoredEpisodeNumberRaw !== null ? (int) $latestStoredEpisodeNumberRaw : null;

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

        $shouldTagAsNew = $existingEpisodesCount > 0;

        if ($shouldTagAsNew) {
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
                'status' => Episode::STATUS_DONE,
                'is_new' => $shouldTagAsNew,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        return Episode::query()->insertOrIgnore($insertPayload);
    }
}
