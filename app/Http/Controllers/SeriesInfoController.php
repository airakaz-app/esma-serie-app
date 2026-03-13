<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeSeriesInfoRequest;
use App\Models\SeriesInfo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

        dispatch(function () use ($listPageUrl, $trackingKey): void {
            Artisan::call('scrape:episodes', [
                '--list-page-url' => $listPageUrl,
                '--tracking-key' => $trackingKey,
            ]);
        })->afterResponse();

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
