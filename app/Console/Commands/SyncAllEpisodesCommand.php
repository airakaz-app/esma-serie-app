<?php

namespace App\Console\Commands;

use App\Services\Episodes\EpisodeSyncService;
use Illuminate\Console\Command;

class SyncAllEpisodesCommand extends Command
{
    protected $signature = 'episodes:sync-all';

    protected $description = 'Synchronise tous les épisodes de toutes les séries.';

    public function __construct(private readonly EpisodeSyncService $episodeSyncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->episodeSyncService->syncAllSeries('command');

        $this->line($result['message']);

        if ($result['status'] === 'error') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
