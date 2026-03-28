<?php

namespace App\Services\Episodes;

use App\Models\Episode;
use App\Models\SeriesInfo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EpisodeSyncService
{
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

        $startedAt = now();
        $seriesProcessed = 0;
        $errors = [];
        $seriesInfos = SeriesInfo::query()
            ->whereNotNull('series_page_url')
            ->where('series_page_url', '!=', '')
            ->orderBy('id')
            ->get(['id', 'title', 'series_page_url']);

        try {
            foreach ($seriesInfos as $seriesInfo) {
                $seriesProcessed++;

                $exitCode = Artisan::call('scrape:episodes', [
                    '--list-page-url' => $seriesInfo->series_page_url,
                ]);

                if ($exitCode !== 0) {
                    $errors[] = sprintf('Échec sync série "%s" (#%d).', $seriesInfo->title ?: 'Sans titre', $seriesInfo->id);
                }
            }

            $newEpisodesCount = Episode::query()
                ->where('created_at', '>=', $startedAt)
                ->count();

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
                'new_episodes_count' => 0,
                'errors' => [$exception->getMessage()],
            ];
        } finally {
            $lock->release();
        }
    }
}
