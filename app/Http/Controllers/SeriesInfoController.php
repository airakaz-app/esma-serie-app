<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeSeriesInfoRequest;
use App\Jobs\RunScrapeEpisodesJob;
use App\Models\SeriesInfo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeriesInfoController extends Controller
{
    public function index(): View
    {
        $seriesInfos = SeriesInfo::query()
            ->withCount('episodes')
            ->withMin('episodes', 'episode_number')
            ->withMax('episodes', 'episode_number')
            ->orderBy('title')
            ->get();

        return view('series_infos.index', [
            'seriesInfos' => $seriesInfos,
        ]);
    }

    public function show(SeriesInfo $seriesInfo): View
    {
        $seriesInfo->load([
            'episodes' => function ($query): void {
                $query
                    ->with(['servers' => function ($serverQuery): void {
                        $serverQuery->orderByDesc('id');
                    }])
                    ->orderBy('episode_number')
                    ->orderBy('id');
            },
        ]);

        return view('series_infos.show', [
            'seriesInfo' => $seriesInfo,
        ]);
    }

    public function scrape(ScrapeSeriesInfoRequest $request): JsonResponse
    {
        $trackingKey = (string) Str::uuid();
        $listPageUrl = $request->string('list_page_url')->toString();
        $retryErrors = $request->boolean('retry_errors');

        Log::info('Demande de scraping reçue.', [
            'tracking_key' => $trackingKey,
            'list_page_url' => $listPageUrl,
            'retry_errors' => $retryErrors,
        ]);

        Cache::put($this->trackingCacheKey($trackingKey), [
            'state' => 'running',
            'message' => 'Scraping mis en file. En attente du worker...',
            'episodesTotal' => 0,
            'episodesProcessed' => 0,
            'progressPercent' => 0,
            'seriesInfoId' => null,
            'seriesInfoTitle' => null,
            'currentEpisodeTitle' => null,
            'lastError' => null,
            'updatedAt' => now()->toIso8601String(),
        ], now()->addHours(2));

        RunScrapeEpisodesJob::dispatch($listPageUrl, $trackingKey, $retryErrors);

        Log::info('Job de scraping mis en file.', [
            'tracking_key' => $trackingKey,
        ]);

        return response()->json([
            'started' => true,
            'trackingKey' => $trackingKey,
        ]);
    }

    public function scrapeStatus(string $trackingKey): JsonResponse
    {
        $status = Cache::get($this->trackingCacheKey($trackingKey));

        if (! is_array($status)) {
            Log::warning('Statut de scraping introuvable.', [
                'tracking_key' => $trackingKey,
            ]);

            return response()->json([
                'state' => 'not_found',
                'message' => 'Aucun scraping trouvé pour cette clé.',
            ], 404);
        }

        Log::info('Statut de scraping récupéré.', [
            'tracking_key' => $trackingKey,
            'state' => $status['state'] ?? null,
            'progress_percent' => $status['progressPercent'] ?? null,
            'episodes_processed' => $status['episodesProcessed'] ?? null,
            'episodes_total' => $status['episodesTotal'] ?? null,
            'current_episode_title' => $status['currentEpisodeTitle'] ?? null,
            'last_error' => $status['lastError'] ?? null,
        ]);

        return response()->json($status);
    }

    private function trackingCacheKey(string $trackingKey): string
    {
        return 'scrape_progress:'.$trackingKey;
    }
}
