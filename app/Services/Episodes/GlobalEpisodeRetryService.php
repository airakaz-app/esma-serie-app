<?php

namespace App\Services\Episodes;

use App\Jobs\RunScrapeEpisodesJob;
use App\Models\Episode;
use App\Models\SeriesInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GlobalEpisodeRetryService
{
    /**
     * Relance la récupération des épisodes en erreur pour TOUTES les séries.
     *
     * @return array{status:string,message:string,series_total:int,series_with_errors:int,jobs_dispatched:int,errors:array<int,string>}
     */
    public function retryAllSeriesErrors(): array
    {
        $lock = Cache::lock('episodes:retry-all', 3600);

        if (! $lock->get()) {
            return [
                'status' => 'busy',
                'message' => 'Un retry est déjà en cours. Veuillez patienter...',
                'series_total' => 0,
                'series_with_errors' => 0,
                'jobs_dispatched' => 0,
                'errors' => [],
            ];
        }

        $jobsDispatched = 0;
        $seriesWithErrors = 0;
        $errors = [];

        try {
            Log::info('🔄 Démarrage du retry global pour tous les épisodes en erreur.');

            // Récupérer toutes les séries (sans eager load des épisodes)
            $seriesInfos = SeriesInfo::query()
                ->orderBy('id')
                ->get(['id', 'title']);

            $seriesTotal = $seriesInfos->count();

            foreach ($seriesInfos as $seriesInfo) {
                try {
                    // Vérifier si cette série a des épisodes problématiques via requête (pas N+1)
                    $problematicEpisodes = Episode::query()
                        ->where('series_info_id', $seriesInfo->id)
                        ->whereIn('status', [
                            Episode::STATUS_PENDING,
                            Episode::STATUS_IN_PROGRESS,
                            Episode::STATUS_ERROR,
                        ])
                        ->count();

                    if ($problematicEpisodes === 0) {
                        // Aucun problème dans cette série
                        continue;
                    }

                    $seriesWithErrors++;

                    Log::info('🔄 Retry dispatché pour série.', [
                        'series_info_id' => $seriesInfo->id,
                        'series_title' => $seriesInfo->title,
                        'problematic_episodes' => $problematicEpisodes,
                    ]);

                    // Dispatcher un job de retry pour cette série
                    RunScrapeEpisodesJob::dispatch(
                        '',                 // listPageUrl (vide : mode retry-only)
                        '',                 // trackingKey (pas de suivi UI)
                        true,               // retryErrors = true (active le retry mode)
                        $seriesInfo->id     // seriesInfoId (scrape cette série uniquement)
                    );

                    $jobsDispatched++;
                } catch (\Throwable $exception) {
                    $errors[] = sprintf(
                        'Échec dispatch retry pour série "%s" (#%d): %s',
                        $seriesInfo->title ?: 'Sans titre',
                        $seriesInfo->id,
                        $exception->getMessage()
                    );

                    Log::error('Erreur dispatch retry.', [
                        'series_info_id' => $seriesInfo->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $status = $errors === [] ? 'completed' : 'completed_with_errors';

            Log::info('✅ Fin du retry global.', [
                'series_total' => $seriesTotal,
                'series_with_errors' => $seriesWithErrors,
                'jobs_dispatched' => $jobsDispatched,
                'errors_count' => count($errors),
            ]);

            return [
                'status' => $status,
                'message' => $jobsDispatched > 0
                    ? "Retry lancé pour {$jobsDispatched} série(s). Vérification des liens en cours..."
                    : 'Aucune série avec épisodes problématiques trouvée.',
                'series_total' => $seriesTotal,
                'series_with_errors' => $seriesWithErrors,
                'jobs_dispatched' => $jobsDispatched,
                'errors' => $errors,
            ];
        } catch (\Throwable $exception) {
            Log::error('Erreur retry global des épisodes.', [
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Erreur pendant le retry global des épisodes.',
                'series_total' => 0,
                'series_with_errors' => 0,
                'jobs_dispatched' => 0,
                'errors' => [$exception->getMessage()],
            ];
        } finally {
            $lock->release();
        }
    }
}
