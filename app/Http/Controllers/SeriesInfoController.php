<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeSeriesInfoRequest;
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

        Cache::put($this->trackingCacheKey($trackingKey), [
            'state' => 'running',
            'message' => 'Initialisation du scraping...',
            'episodesTotal' => 0,
            'episodesProcessed' => 0,
            'progressPercent' => 0,
            'seriesInfoId' => null,
            'seriesInfoTitle' => null,
        ], now()->addHours(2));

        $escapedPhpBinary = escapeshellarg(PHP_BINARY);
        $escapedArtisanPath = escapeshellarg(base_path('artisan'));
        $escapedListPageUrl = escapeshellarg($listPageUrl);
        $escapedTrackingKey = escapeshellarg($trackingKey);

        $command = sprintf(
            'cd %s && nohup %s %s scrape:episodes --list-page-url=%s --tracking-key=%s >> %s 2>&1 &',
            escapeshellarg(base_path()),
            $escapedPhpBinary,
            $escapedArtisanPath,
            $escapedListPageUrl,
            $escapedTrackingKey,
            escapeshellarg(storage_path('logs/scrape-detached.log')),
        );

        exec($command, $output, $resultCode);

        Log::info('Lancement du scraping en tâche détachée.', [
            'tracking_key' => $trackingKey,
            'result_code' => $resultCode,
            'command' => $command,
        ]);

        if ($resultCode !== 0) {
            Cache::put($this->trackingCacheKey($trackingKey), [
                'state' => 'error',
                'message' => 'Impossible de lancer le scraping en arrière-plan.',
                'episodesTotal' => 0,
                'episodesProcessed' => 0,
                'progressPercent' => 0,
                'seriesInfoId' => null,
                'seriesInfoTitle' => null,
            ], now()->addHours(2));

            return response()->json([
                'started' => false,
                'message' => 'Le scraping n’a pas pu démarrer.',
            ], 500);
        }

        return response()->json([
            'started' => true,
            'trackingKey' => $trackingKey,
        ]);
    }

    public function scrapeStatus(string $trackingKey): JsonResponse
    {
        $status = Cache::get($this->trackingCacheKey($trackingKey));

        if (! is_array($status)) {
            return response()->json([
                'state' => 'not_found',
                'message' => 'Aucun scraping trouvé pour cette clé.',
            ], 404);
        }

        return response()->json($status);
    }

    private function trackingCacheKey(string $trackingKey): string
    {
        return 'scrape_progress:'.$trackingKey;
    }
}
