<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeSeriesInfoRequest;
use App\Models\SeriesInfo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

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
        $latestSeriesInfoId = (int) (SeriesInfo::query()->max('id') ?? 0);

        Artisan::call('scrape:episodes', [
            '--list-page-url' => $request->string('list_page_url')->toString(),
        ]);

        $createdSeriesInfo = SeriesInfo::query()
            ->where('id', '>', $latestSeriesInfoId)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'created' => $createdSeriesInfo !== null,
            'seriesInfoId' => $createdSeriesInfo?->id,
            'seriesInfoTitle' => $createdSeriesInfo?->title,
        ]);
    }
}
