<?php

namespace App\Http\Controllers;

use App\Models\SeriesInfo;
use Illuminate\Contracts\View\View;

class SeriesInfoController extends Controller
{
    public function index(): View
    {
        $seriesInfos = SeriesInfo::query()
            ->withCount('episodes')
            ->orderBy('title')
            ->get();

        return view('series_infos.index', [
            'seriesInfos' => $seriesInfos,
        ]);
    }

    public function show(SeriesInfo $seriesInfo): View
    {
        $seriesInfo->load(['episodes' => function ($query): void {
            $query->orderBy('episode_number')->orderBy('id');
        }]);

        return view('series_infos.show', [
            'seriesInfo' => $seriesInfo,
        ]);
    }
}
