<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeSeriesInfoRequest;
use App\Http\Requests\DeleteEpisodesRequest;
use App\Jobs\RunScrapeEpisodesJob;
use App\Models\Episode;
use App\Models\SeriesInfo;
use App\Models\VideoWatchHistory;
use App\Services\Scraper\HtmlFetcher;
use App\Services\Scraper\EpisodeListScraper;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(SeriesInfo $seriesInfo, Request $request): View
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

        $watchHistoriesByKey = collect();
        $user = $request->user();

        if ($user !== null) {
            $episodeVideoKeys = $seriesInfo->episodes
                ->map(fn (Episode $episode): string => 'episode-'.$episode->id)
                ->all();

            $watchHistoriesByKey = VideoWatchHistory::query()
                ->where('user_id', $user->id)
                ->whereIn('video_key', $episodeVideoKeys)
                ->get()
                ->keyBy('video_key');
        }

        return view('series_infos.show', [
            'seriesInfo' => $seriesInfo,
            'watchHistoriesByKey' => $watchHistoriesByKey,
        ]);
    }

    public function searchExternal(Request $request, HtmlFetcher $fetcher): JsonResponse
    {
        $query = trim((string) $request->string('q'));
        if ($query === '') {
            return response()->json(['results' => []]);
        }

        $baseUrl = rtrim((string) config('scraper.source_base_url', 'https://n.esheaq.onl'), '/');

        try {
            $html = $fetcher->fetch($baseUrl.'/?s='.urlencode($query));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Impossible de contacter le site source : '.$e->getMessage()], 502);
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);

        $results = [];

        // Cherche toutes les balises <a> qui pointent vers /watch/ (URLs de séries)
        $anchors = $xpath->query("//a[contains(@href, '/watch/')]");
        $seen = [];

        if ($anchors !== false) {
            foreach ($anchors as $anchor) {
                if (! $anchor instanceof \DOMElement) {
                    continue;
                }

                $href = trim((string) $anchor->getAttribute('href'));
                // Exclure les URLs d'épisodes /watch/slug/see/
                if (str_contains($href, '/see/') || $href === '') {
                    continue;
                }
                if (isset($seen[$href])) {
                    continue;
                }
                $seen[$href] = true;

                // Cherche un titre : d'abord dans .title, sinon le texte de l'ancre
                $titleNode = $xpath->query(
                    ".//*[contains(concat(' ', normalize-space(@class), ' '), ' title ')]",
                    $anchor->parentNode instanceof \DOMElement ? $anchor->parentNode : $anchor
                )?->item(0);

                $title = $titleNode ? trim($titleNode->textContent) : trim($anchor->textContent);

                if ($title === '') {
                    $title = trim((string) $anchor->getAttribute('title'));
                }
                if ($title === '') {
                    continue;
                }

                // Cherche l'image dans l'article parent
                $article = $anchor;
                while ($article !== null && ! ($article instanceof \DOMElement && strtolower($article->tagName) === 'article')) {
                    $article = $article->parentNode instanceof \DOMElement ? $article->parentNode : null;
                }

                $imageUrl = null;
                if ($article instanceof \DOMElement) {
                    $imgNode = $xpath->query('.//img', $article)?->item(0);
                    if ($imgNode instanceof \DOMElement) {
                        foreach (['src', 'data-src', 'data-image', 'data-lazy-src'] as $attr) {
                            $src = trim((string) $imgNode->getAttribute($attr));
                            if ($src !== '' && ! str_starts_with($src, 'data:')) {
                                $imageUrl = $src;
                                break;
                            }
                        }
                    }
                }

                $results[] = ['title' => $title, 'url' => $href, 'image' => $imageUrl];

                if (count($results) >= 20) {
                    break;
                }
            }
        }

        return response()->json(['results' => $results]);
    }

    public function scrape(ScrapeSeriesInfoRequest $request): JsonResponse
    {
        $trackingKey = (string) Str::uuid();
        $listPageUrl = $request->string('list_page_url')->toString();
        $retryErrors = $request->boolean('retry_errors');
        $episodeStart = $request->filled('episode_start') ? $request->integer('episode_start') : null;
        $episodeEnd = $request->filled('episode_end') ? $request->integer('episode_end') : null;

        Log::info('Demande de scraping reçue.', [
            'tracking_key' => $trackingKey,
            'list_page_url' => $listPageUrl,
            'retry_errors' => $retryErrors,
            'episode_start' => $episodeStart,
            'episode_end' => $episodeEnd,
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

        RunScrapeEpisodesJob::dispatch($listPageUrl, $trackingKey, $retryErrors, null, $episodeStart, $episodeEnd);

        Log::info('Job de scraping mis en file.', [
            'tracking_key' => $trackingKey,
        ]);

        return response()->json([
            'started' => true,
            'trackingKey' => $trackingKey,
        ]);
    }

    public function scrapePreview(ScrapeSeriesInfoRequest $request, EpisodeListScraper $episodeListScraper): JsonResponse
    {
        $listPageUrl = $request->string('list_page_url')->toString();

        try {
            $episodes = $episodeListScraper->scrape($listPageUrl);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Impossible de récupérer les épisodes pour cette URL.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        $episodeNumbers = collect($episodes)
            ->pluck('episode_number')
            ->filter(fn ($episodeNumber): bool => is_int($episodeNumber))
            ->values();
        $coverImageUrl = collect($episodes)
            ->pluck('image_url')
            ->first(fn ($imageUrl): bool => is_string($imageUrl) && $imageUrl !== '');

        return response()->json([
            'episodesTotal' => count($episodes),
            'hasEpisodeNumbers' => $episodeNumbers->isNotEmpty(),
            'episodeMin' => $episodeNumbers->isEmpty() ? null : $episodeNumbers->min(),
            'episodeMax' => $episodeNumbers->isEmpty() ? null : $episodeNumbers->max(),
            'coverImageUrl' => is_string($coverImageUrl) ? $coverImageUrl : null,
        ]);
    }

    public function retryErrors(SeriesInfo $seriesInfo): JsonResponse
    {
        $trackingKey = (string) Str::uuid();

        Log::info('Retry erreurs demandé.', [
            'tracking_key'   => $trackingKey,
            'series_info_id' => $seriesInfo->id,
        ]);

        Cache::put($this->trackingCacheKey($trackingKey), [
            'state'               => 'running',
            'message'             => 'Retry en file. En attente du worker...',
            'episodesTotal'       => 0,
            'episodesProcessed'   => 0,
            'progressPercent'     => 0,
            'seriesInfoId'        => $seriesInfo->id,
            'seriesInfoTitle'     => $seriesInfo->title,
            'currentEpisodeTitle' => null,
            'lastError'           => null,
            'updatedAt'           => now()->toIso8601String(),
            'events'              => [[
                'time'    => now()->format('H:i:s'),
                'level'   => 'info',
                'message' => 'Retry des épisodes en erreur initialisé.',
            ]],
        ], now()->addHours(2));

        RunScrapeEpisodesJob::dispatch('', $trackingKey, true, $seriesInfo->id);

        return response()->json([
            'started'     => true,
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

        $fileName = $this->downloadFileName($episode);
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

    private function downloadFileName(Episode $episode): string
    {
        $baseName = trim($episode->title ?: sprintf('episode-%d', $episode->id));

        if ($baseName === '') {
            $baseName = sprintf('episode-%d', $episode->id);
        }

        $baseName = str_replace(['\\', '/', "\0"], '-', $baseName);

        return sprintf('%s.%s', $baseName, 'mp4');
    }

    private function trackingCacheKey(string $trackingKey): string
    {
        return 'scrape_progress:'.$trackingKey;
    }
}
