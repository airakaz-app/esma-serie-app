<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeSeriesInfoRequest;
use App\Http\Requests\DeleteEpisodesRequest;
use App\Jobs\RunScrapeEpisodesJob;
use App\Models\Episode;
use App\Models\SeriesInfo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
            'events' => [
                [
                    'time' => now()->format('H:i:s'),
                    'level' => 'info',
                    'message' => 'Scraping initialisé.',
                ],
            ],
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

    public function destroy(SeriesInfo $seriesInfo): RedirectResponse
    {
        $seriesTitle = $seriesInfo->title ?: 'Sans titre';

        $seriesInfo->episodes()->delete();

        $seriesInfo->delete();

        return redirect()
            ->route('series-infos.index')
            ->with('status', sprintf('La série "%s" a été supprimée.', $seriesTitle));
    }

    public function destroyEpisode(SeriesInfo $seriesInfo, Episode $episode): RedirectResponse
    {
        if ($episode->series_info_id !== $seriesInfo->id) {
            abort(404);
        }

        $episodeTitle = $episode->title;

        $episode->delete();

        return redirect()
            ->route('series-infos.show', $seriesInfo)
            ->with('status', sprintf('L\'épisode "%s" a été supprimé.', $episodeTitle));
    }

    public function bulkDestroyEpisodes(DeleteEpisodesRequest $request, SeriesInfo $seriesInfo): RedirectResponse
    {
        $episodeIds = $request->validated('episode_ids');

        $deletedCount = Episode::query()
            ->where('series_info_id', $seriesInfo->id)
            ->whereIn('id', $episodeIds)
            ->delete();

        return redirect()
            ->route('series-infos.show', $seriesInfo)
            ->with('status', sprintf('%d épisode(s) supprimé(s).', $deletedCount));
    }

    public function downloadEpisode(SeriesInfo $seriesInfo, Episode $episode): StreamedResponse|RedirectResponse
    {
        if ($episode->series_info_id !== $seriesInfo->id) {
            abort(404);
        }

        $finalUrl = $episode->servers()
            ->whereNotNull('final_url')
            ->where('final_url', '!=', '')
            ->orderByDesc('id')
            ->value('final_url');

        if (! is_string($finalUrl) || $finalUrl === '') {
            return redirect()
                ->route('series-infos.show', $seriesInfo)
                ->withErrors([
                    'download' => sprintf('Le lien de téléchargement est indisponible pour "%s".', $episode->title),
                ]);
        }

        $downloadResponse = Http::withOptions([
            'stream' => true,
            'allow_redirects' => true,
        ])->get($finalUrl);

        if (! $downloadResponse->successful()) {
            return redirect()
                ->route('series-infos.show', $seriesInfo)
                ->withErrors([
                    'download' => sprintf('Impossible de télécharger "%s" pour le moment.', $episode->title),
                ]);
        }

        $fileName = $this->downloadFileName($episode, $finalUrl);
        $contentType = $downloadResponse->header('Content-Type') ?: 'application/octet-stream';
        $bodyStream = $downloadResponse->toPsrResponse()->getBody();

        return response()->streamDownload(function () use ($bodyStream): void {
            while (! $bodyStream->eof()) {
                echo $bodyStream->read(1024 * 1024);
                flush();
            }
        }, $fileName, [
            'Content-Type' => $contentType,
        ]);
    }

    private function downloadFileName(Episode $episode, string $finalUrl): string
    {
        $extension = strtolower((string) pathinfo(parse_url($finalUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        if ($extension === '') {
            $extension = 'mp4';
        }

        $baseName = Str::slug($episode->title ?: sprintf('episode-%d', $episode->id));

        if ($baseName === '') {
            $baseName = sprintf('episode-%d', $episode->id);
        }

        return sprintf('%s.%s', $baseName, $extension);
    }

    private function trackingCacheKey(string $trackingKey): string
    {
        return 'scrape_progress:'.$trackingKey;
    }
}
