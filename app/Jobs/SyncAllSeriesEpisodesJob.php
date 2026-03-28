<?php

namespace App\Jobs;

use App\Services\Episodes\EpisodeSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncAllSeriesEpisodesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function handle(EpisodeSyncService $episodeSyncService): void
    {
        $result = $episodeSyncService->syncAllSeries('scheduler');

        Log::info('Synchronisation planifiée des épisodes exécutée.', $result);
    }
}
