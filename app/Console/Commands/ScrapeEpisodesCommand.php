<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\EpisodeServer;
use App\Models\SeriesInfo;
use App\Services\Scraper\BrowserClickService;
use App\Services\Scraper\EpisodeListScraper;
use App\Services\Scraper\EpisodePageScraper;
use App\Services\Scraper\SeriesInfoScraper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScrapeEpisodesCommand extends Command
{
    protected $signature = 'scrape:episodes
        {--limit= : Nombre max de serveurs à traiter}
        {--episode-id= : Traiter un seul épisode}
        {--list-page-url= : URL de la page liste à scraper}
        {--tracking-key= : Clé de suivi de progression}
        {--retry-errors : Rejouer les statuts error}
        {--only-pending : Traiter uniquement les statuts pending}';

    protected $description = 'Scrape automatiquement les épisodes, serveurs et URLs finales.';

    private ?string $trackingKey = null;

    private ?int $trackedSeriesInfoId = null;

    private ?string $trackedSeriesInfoTitle = null;

    private int $episodesTotal = 0;

    private int $episodesProcessed = 0;

    private ?string $currentEpisodeTitle = null;

    private ?string $lastError = null;

    public function __construct(
        private readonly EpisodeListScraper $listScraper,
        private readonly EpisodePageScraper $pageScraper,
        private readonly BrowserClickService $browser,
        private readonly SeriesInfoScraper $seriesInfoScraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->trackingKey = $this->option('tracking-key') ? (string) $this->option('tracking-key') : null;
        $this->updateTrackingStatus('running', 'Initialisation du scraping...');

        $listUrl = (string) ($this->option('list-page-url') ?: config('scraper.list_page_url'));
        if ($listUrl === '' && ! $this->option('episode-id')) {
            $this->error('SCRAPER_LIST_PAGE_URL est vide.');
            $this->updateTrackingStatus('error', 'URL de liste manquante.');

            return self::FAILURE;
        }

        $this->scanEpisodes($listUrl);

        $serversProcessed = 0;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $episodeQuery = $this->episodeQuery();

        $this->episodesTotal = (clone $episodeQuery)->count();
        $this->episodesProcessed = 0;
        $this->info(sprintf('Épisodes à traiter: %d', $this->episodesTotal));
        $this->updateTrackingStatus('running', 'Récupération des épisodes en cours...');

        foreach ($episodeQuery->lazyById(100) as $episode) {
            $this->currentEpisodeTitle = $episode->title;
            $this->line("- Épisode #{$episode->id}: {$episode->title}");
            $this->processEpisode($episode, $serversProcessed, $limit);
            $this->episodesProcessed++;
            $this->updateTrackingStatus('running', 'Récupération des épisodes en cours...');

            if ($limit !== null && $serversProcessed >= $limit) {
                $this->warn('Limite atteinte, arrêt propre.');
                break;
            }
        }

        $this->currentEpisodeTitle = null;
        $this->updateTrackingStatus('completed', 'Scraping terminé.');

        return self::SUCCESS;
    }

    private function scanEpisodes(string $listUrl): void
    {
        if ($this->option('episode-id')) {
            return;
        }

        $this->info('Scan page liste...');

        try {
            $episodes = $this->listScraper->scrape($listUrl);

            $this->upsertEpisodes($episodes);

            $this->syncSeriesInfoFromEpisodes($episodes);

            $this->info(sprintf('Épisodes détectés: %d', count($episodes)));
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            Log::error('Erreur scan liste épisodes', ['error' => $e->getMessage()]);
            $this->error('Erreur pendant le scan liste: '.$e->getMessage());
            $this->updateTrackingStatus('error', 'Erreur pendant le scan de la page liste.');
        }
    }

    private function episodeQuery(): Builder
    {
        $query = Episode::query();

        if ($episodeId = $this->option('episode-id')) {
            $query->whereKey((int) $episodeId);
        } else {
            $query->where('status', '!=', Episode::STATUS_DONE);
        }

        if ($this->option('only-pending')) {
            $query->where('status', Episode::STATUS_PENDING);
        }

        if (! $this->option('retry-errors')) {
            $query->where('status', '!=', Episode::STATUS_ERROR);
        }

        return $query;
    }

    /**
     * @param array<int, array{title:string,page_url:string,episode_number:?int,image_url:?string}> $episodes
     */
    private function syncSeriesInfoFromEpisodes(array $episodes): void
    {
        if ($episodes === []) {
            return;
        }

        $firstEpisodeUrl = (string) ($episodes[0]['page_url'] ?? '');
        if ($firstEpisodeUrl === '') {
            return;
        }

        try {
            $seriesInfo = $this->seriesInfoScraper->scrapeFromEpisodeUrl($firstEpisodeUrl);

            $seriesInfoModel = SeriesInfo::query()->updateOrCreate(
                ['source_episode_page_url' => $seriesInfo['source_episode_page_url']],
                [
                    'series_page_url' => $seriesInfo['series_page_url'],
                    'title' => $seriesInfo['title'],
                    'title_url' => $seriesInfo['title_url'],
                    'cover_image_url' => $seriesInfo['cover_image_url'],
                    'story' => $seriesInfo['story'],
                    'categories' => $seriesInfo['categories'],
                    'actors' => $seriesInfo['actors'],
                ],
            );

            $this->trackedSeriesInfoId = $seriesInfoModel->id;
            $this->trackedSeriesInfoTitle = $seriesInfoModel->title;
            $this->updateTrackingStatus('running', 'Fiche série créée. Récupération des épisodes en cours...');

            $episodeUrls = collect($episodes)
                ->pluck('page_url')
                ->filter(fn (?string $pageUrl): bool => $pageUrl !== null && $pageUrl !== '')
                ->values();

            if ($episodeUrls->isNotEmpty()) {
                Episode::query()
                    ->whereIn('page_url', $episodeUrls)
                    ->update(['series_info_id' => $seriesInfoModel->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('Erreur récupération infos série', [
                'source_episode_page_url' => $firstEpisodeUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processEpisode(Episode $episode, int &$serversProcessed, ?int $limit): void
    {
        $episode->forceFill([
            'status' => Episode::STATUS_IN_PROGRESS,
            'error_message' => null,
            'last_scraped_at' => now(),
        ])->save();

        try {
            $servers = $this->pageScraper->extractServers($episode->page_url);

            $this->upsertEpisodeServers($episode, $servers);

            $serverQuery = $episode->servers()->orderBy('id');

            if ($this->option('only-pending')) {
                $serverQuery->where('status', EpisodeServer::STATUS_PENDING);
            } elseif (! $this->option('retry-errors')) {
                $serverQuery->where('status', '!=', EpisodeServer::STATUS_DONE)
                    ->where('status', '!=', EpisodeServer::STATUS_ERROR);
            } else {
                $serverQuery->where('status', '!=', EpisodeServer::STATUS_DONE);
            }

            foreach ($serverQuery->cursor() as $server) {
                if ($limit !== null && $serversProcessed >= $limit) {
                    break;
                }

                $this->processServer($server);
                $serversProcessed++;
            }

            $this->refreshEpisodeStatus($episode);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            $episode->forceFill([
                'status' => Episode::STATUS_ERROR,
                'error_message' => $e->getMessage(),
                'last_scraped_at' => now(),
            ])->save();

            Log::error('Erreur traitement épisode', [
                'episode_id' => $episode->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, array{title:string,page_url:string,episode_number:?int,image_url:?string}> $episodes
     */
    private function upsertEpisodes(array $episodes): void
    {
        if ($episodes === []) {
            return;
        }

        $now = now();

        $payload = collect($episodes)
            ->filter(fn (array $episode): bool => isset($episode['page_url']) && trim((string) $episode['page_url']) !== '')
            ->map(fn (array $episode): array => [
                'page_url' => (string) $episode['page_url'],
                'title' => (string) $episode['title'],
                'episode_number' => $episode['episode_number'] ?? null,
                'image_url' => $episode['image_url'] ?? null,
                'updated_at' => $now,
                'created_at' => $now,
            ])
            ->values()
            ->all();

        if ($payload === []) {
            return;
        }

        Episode::query()->upsert(
            $payload,
            ['page_url'],
            ['title', 'episode_number', 'image_url', 'updated_at'],
        );
    }

    /**
     * @param array<int, array{server_name:?string,host:?string,server_page_url:string}> $servers
     */
    private function upsertEpisodeServers(Episode $episode, array $servers): void
    {
        if ($servers === []) {
            return;
        }

        $now = now();

        $payload = collect($servers)
            ->filter(fn (array $server): bool => isset($server['server_page_url']) && trim((string) $server['server_page_url']) !== '')
            ->map(fn (array $server): array => [
                'server_page_url' => (string) $server['server_page_url'],
                'episode_id' => $episode->id,
                'server_name' => $server['server_name'],
                'host' => $server['host'],
                'updated_at' => $now,
                'created_at' => $now,
            ])
            ->values()
            ->all();

        if ($payload === []) {
            return;
        }

        EpisodeServer::query()->upsert(
            $payload,
            ['server_page_url'],
            ['episode_id', 'server_name', 'host', 'updated_at'],
        );
    }

    private function processServer(EpisodeServer $server): void
    {
        Log::info('Début traitement serveur épisode.', [
            'server_id' => $server->id,
            'episode_id' => $server->episode_id,
            'server_name' => $server->server_name,
            'host' => $server->host,
            'status' => $server->status,
            'retry_count' => $server->retry_count,
            'has_iframe_url' => $server->iframe_url !== null,
            'has_final_url' => $server->final_url !== null,
        ]);

        if ($server->status === EpisodeServer::STATUS_DONE) {
            Log::info('Serveur déjà traité, saut.', [
                'server_id' => $server->id,
            ]);

            return;
        }

        $maxRetries = (int) config('scraper.max_retries', 3);
        if (! $this->option('retry-errors') && $server->retry_count >= $maxRetries) {
            $this->warn("  Serveur #{$server->id} ignoré: retry max atteint.");

            Log::warning('Serveur ignoré: retry max atteint.', [
                'server_id' => $server->id,
                'retry_count' => $server->retry_count,
                'max_retries' => $maxRetries,
            ]);

            return;
        }

        $this->line("  Serveur #{$server->id} {$server->server_name} ({$server->host})");

        $server->forceFill([
            'status' => EpisodeServer::STATUS_IN_PROGRESS,
            'error_message' => null,
            'last_scraped_at' => now(),
        ])->save();

        try {
            if (! $server->iframe_url) {
                Log::info('Extraction iframe_url depuis server_page_url.', [
                    'server_id' => $server->id,
                    'server_page_url' => $server->server_page_url,
                ]);

                $server->iframe_url = $this->pageScraper->extractIframeUrl($server->server_page_url);
                $server->save();

                Log::info('Résultat extraction iframe_url.', [
                    'server_id' => $server->id,
                    'iframe_url' => $server->iframe_url,
                ]);
            }

            if (! $server->iframe_url) {
                throw new \RuntimeException('Iframe introuvable');
            }

            if (! $server->final_url) {
                Log::info('Résolution URL finale via BrowserClickService.', [
                    'server_id' => $server->id,
                    'iframe_url' => $server->iframe_url,
                ]);

                $browserResult = $this->browser->resolveDownloadUrl($server->iframe_url);

                Log::info('Résultat BrowserClickService.', [
                    'server_id' => $server->id,
                    'success' => $browserResult['success'],
                    'final_url' => $browserResult['final_url'] ?? null,
                    'error' => $browserResult['error'] ?? null,
                ]);

                if (! $browserResult['success']) {
                    throw new \RuntimeException((string) ($browserResult['error'] ?? 'Erreur browser'));
                }

                $summary = $this->browser->summarizeHtml((string) ($browserResult['final_html'] ?? ''));

                $server->forceFill([
                    'click_success' => true,
                    'final_url' => $browserResult['final_url'],
                    'result_title' => $summary['result_title'],
                    'result_h1' => $summary['result_h1'],
                    'result_preview' => $summary['result_preview'],
                    'status' => EpisodeServer::STATUS_DONE,
                    'error_message' => null,
                    'last_scraped_at' => now(),
                ])->save();

                Log::info('Serveur traité avec succès.', [
                    'server_id' => $server->id,
                    'status' => EpisodeServer::STATUS_DONE,
                    'final_url' => $server->final_url,
                    'result_title' => $server->result_title,
                ]);
            } else {
                $server->forceFill([
                    'status' => EpisodeServer::STATUS_DONE,
                    'last_scraped_at' => now(),
                ])->save();

                Log::info('Serveur marqué done car final_url déjà présente.', [
                    'server_id' => $server->id,
                    'final_url' => $server->final_url,
                ]);
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            $server->forceFill([
                'status' => EpisodeServer::STATUS_ERROR,
                'error_message' => $e->getMessage(),
                'retry_count' => $server->retry_count + 1,
                'last_scraped_at' => now(),
            ])->save();

            Log::error('Erreur traitement serveur', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
                'trace_preview' => mb_substr($e->getTraceAsString(), 0, 1200),
            ]);
        } finally {
            $freshServer = $server->fresh();

            Log::info('Fin traitement serveur épisode.', [
                'server_id' => $server->id,
                'status' => $freshServer?->status,
                'retry_count' => $freshServer?->retry_count,
                'error_message' => $freshServer?->error_message,
            ]);
        }
    }

    private function refreshEpisodeStatus(Episode $episode): void
    {
        $states = $episode->servers()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $totalServers = (int) $states->sum();
        $doneServers = (int) ($states[EpisodeServer::STATUS_DONE] ?? 0);

        if ($doneServers > 0 && $doneServers === $totalServers) {
            $status = Episode::STATUS_DONE;
        } elseif (($states[EpisodeServer::STATUS_ERROR] ?? 0) > 0 && ($states[EpisodeServer::STATUS_PENDING] ?? 0) === 0 && ($states[EpisodeServer::STATUS_IN_PROGRESS] ?? 0) === 0) {
            $status = Episode::STATUS_ERROR;
        } else {
            $status = Episode::STATUS_IN_PROGRESS;
        }

        $episode->forceFill([
            'status' => $status,
            'last_scraped_at' => now(),
        ])->save();
    }

    private function updateTrackingStatus(string $state, string $message): void
    {
        if ($this->trackingKey === null || $this->trackingKey === '') {
            return;
        }

        $percent = $this->episodesTotal > 0
            ? min(100, (int) round(($this->episodesProcessed / $this->episodesTotal) * 100))
            : 0;

        Cache::put($this->trackingCacheKey(), [
            'state' => $state,
            'message' => $message,
            'episodesTotal' => $this->episodesTotal,
            'episodesProcessed' => $this->episodesProcessed,
            'progressPercent' => $percent,
            'seriesInfoId' => $this->trackedSeriesInfoId,
            'seriesInfoTitle' => $this->trackedSeriesInfoTitle,
            'currentEpisodeTitle' => $this->currentEpisodeTitle,
            'lastError' => $this->lastError,
            'updatedAt' => now()->toIso8601String(),
        ], now()->addHours(2));
    }

    private function trackingCacheKey(): string
    {
        return 'scrape_progress:'.$this->trackingKey;
    }
}
