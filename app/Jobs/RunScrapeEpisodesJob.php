<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunScrapeEpisodesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public string $listPageUrl,
        public string $trackingKey,
        public bool $retryErrors = false,
    ) {
    }

    public function handle(): void
    {
        Log::info('Démarrage du job de scraping.', [
            'tracking_key' => $this->trackingKey,
            'list_page_url' => $this->listPageUrl,
        ]);

        $exitCode = Artisan::call('scrape:episodes', [
            '--list-page-url' => $this->listPageUrl,
            '--tracking-key' => $this->trackingKey,
            '--retry-errors' => $this->retryErrors,
        ]);

        Log::info('Fin du job de scraping.', [
            'tracking_key' => $this->trackingKey,
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ]);

        if ($exitCode !== 0) {
            Cache::put($this->trackingCacheKey(), [
                'state' => 'error',
                'message' => 'Le scraping a échoué pendant l’exécution du worker.',
                'episodesTotal' => 0,
                'episodesProcessed' => 0,
                'progressPercent' => 0,
                'seriesInfoId' => null,
                'seriesInfoTitle' => null,
                'currentEpisodeTitle' => null,
                'lastError' => 'Exit code '.$exitCode,
                'updatedAt' => now()->toIso8601String(),
                'events' => [
                    [
                        'time' => now()->format('H:i:s'),
                        'level' => 'error',
                        'message' => 'Le worker a retourné un code de sortie en erreur.',
                    ],
                ],
            ], now()->addHours(2));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::put($this->trackingCacheKey(), [
            'state' => 'error',
            'message' => 'Le job de scraping a échoué.',
            'episodesTotal' => 0,
            'episodesProcessed' => 0,
            'progressPercent' => 0,
            'seriesInfoId' => null,
            'seriesInfoTitle' => null,
            'currentEpisodeTitle' => null,
            'lastError' => $exception?->getMessage(),
            'updatedAt' => now()->toIso8601String(),
            'events' => [
                [
                    'time' => now()->format('H:i:s'),
                    'level' => 'error',
                    'message' => 'Le job de scraping a échoué.',
                ],
            ],
        ], now()->addHours(2));

        Log::error('Échec du job de scraping.', [
            'tracking_key' => $this->trackingKey,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function trackingCacheKey(): string
    {
        return 'scrape_progress:'.$this->trackingKey;
    }
}
