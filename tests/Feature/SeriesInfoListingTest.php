<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\EpisodeServer;
use App\Jobs\RunScrapeEpisodesJob;
use App\Models\SeriesInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeriesInfoListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_infos_page_displays_series_infos(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/serie-1/episode-1/see/',
            'series_page_url' => 'https://example.com/serie-1',
            'title' => 'Serie One',
        ]);

        Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode 1',
            'page_url' => 'https://example.com/serie-1/episode-1/see/',
        ]);

        $response = $this->get(route('series-infos.index'));

        $response->assertOk();
        $response->assertSee('Serie One');
        $response->assertSee('1 épisode(s)');
    }

    public function test_series_info_page_displays_linked_episodes(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/serie-2/episode-1/see/',
            'series_page_url' => 'https://example.com/serie-2',
            'title' => 'Serie Two',
        ]);

        Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode 1',
            'page_url' => 'https://example.com/serie-2/episode-1/see/',
            'episode_number' => 1,
        ]);

        Episode::query()->create([
            'title' => 'Other Episode',
            'page_url' => 'https://example.com/other/episode-1/see/',
            'episode_number' => 1,
        ]);

        $response = $this->get(route('series-infos.show', $seriesInfo));

        $response->assertOk();
        $response->assertSee('Episode 1');
        $response->assertDontSee('Other Episode');
        $response->assertSee('Refresh épisodes');
        $response->assertSee('data-list-page-url="https://example.com/serie-2"', false);
    }

    public function test_series_info_page_links_episode_card_to_final_url(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/serie-3/episode-1/see/',
            'series_page_url' => 'https://example.com/serie-3',
            'title' => 'Serie Three',
        ]);

        $episode = Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode Final',
            'page_url' => 'https://example.com/serie-3/episode-1/see/',
            'episode_number' => 1,
        ]);

        EpisodeServer::query()->create([
            'episode_id' => $episode->id,
            'server_name' => 'Main Server',
            'host' => 'vdesk',
            'server_page_url' => 'https://example.com/server/1',
            'final_url' => 'https://stream.example.com/final-episode-1',
            'status' => EpisodeServer::STATUS_DONE,
        ]);

        $response = $this->get(route('series-infos.show', $seriesInfo));

        $response->assertOk();
        $response->assertSee('https://stream.example.com/final-episode-1');
        $response->assertSee('Lire maintenant');
    }

    public function test_series_infos_page_can_start_scraping_with_tracking_key(): void
    {
        Queue::fake();

        $response = $this->postJson(route('series-infos.scrape'), [
            'list_page_url' => 'https://example.com/series',
        ]);

        $response->assertOk();
        $response->assertJsonPath('started', true);
        $this->assertIsString($response->json('trackingKey'));

        Queue::assertPushed(RunScrapeEpisodesJob::class, function (RunScrapeEpisodesJob $job): bool {
            return $job->listPageUrl === 'https://example.com/series'
                && is_string($job->trackingKey)
                && $job->trackingKey !== ''
                && $job->retryErrors === false;
        });
    }

    public function test_series_infos_page_can_start_refresh_with_retry_errors_enabled(): void
    {
        Queue::fake();

        $response = $this->postJson(route('series-infos.scrape'), [
            'list_page_url' => 'https://example.com/series',
            'retry_errors' => true,
        ]);

        $response->assertOk();

        Queue::assertPushed(RunScrapeEpisodesJob::class, function (RunScrapeEpisodesJob $job): bool {
            return $job->listPageUrl === 'https://example.com/series'
                && $job->retryErrors === true;
        });
    }

    public function test_series_infos_page_can_get_scrape_status_with_tracking_key(): void
    {
        Cache::put('scrape_progress:test-key', [
            'state' => 'running',
            'message' => 'Récupération des épisodes en cours...',
            'episodesTotal' => 12,
            'episodesProcessed' => 4,
            'progressPercent' => 33,
            'seriesInfoId' => 11,
            'seriesInfoTitle' => 'Serie Eleven',
            'currentEpisodeTitle' => 'Episode 4',
            'lastError' => null,
            'updatedAt' => now()->toIso8601String(),
        ], now()->addMinutes(5));

        $response = $this->getJson(route('series-infos.scrape-status', 'test-key'));

        $response->assertOk();
        $response->assertJsonPath('state', 'running');
        $response->assertJsonPath('progressPercent', 33);
        $response->assertJsonPath('seriesInfoId', 11);
        $response->assertJsonPath('currentEpisodeTitle', 'Episode 4');
    }

    public function test_series_infos_page_scrape_requires_valid_url(): void
    {
        $response = $this->postJson(route('series-infos.scrape'), [
            'list_page_url' => 'invalid-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['list_page_url']);
    }

    public function test_series_can_be_deleted_with_linked_episodes(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/serie-delete/episode-1/see/',
            'series_page_url' => 'https://example.com/serie-delete',
            'title' => 'Serie Delete',
        ]);

        $episode = Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode to delete',
            'page_url' => 'https://example.com/serie-delete/episode-1/see/',
        ]);

        EpisodeServer::query()->create([
            'episode_id' => $episode->id,
            'server_name' => 'Server',
            'host' => 'Host',
            'server_page_url' => 'https://example.com/server',
            'status' => EpisodeServer::STATUS_DONE,
        ]);

        $response = $this->delete(route('series-infos.destroy', $seriesInfo));

        $response->assertRedirect(route('series-infos.index'));
        $this->assertDatabaseMissing('series_infos', ['id' => $seriesInfo->id]);
        $this->assertDatabaseMissing('episodes', ['id' => $episode->id]);
        $this->assertDatabaseCount('episode_servers', 0);
    }

    public function test_episode_can_be_deleted_from_series_page(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/serie-ep-delete/episode-1/see/',
            'series_page_url' => 'https://example.com/serie-ep-delete',
            'title' => 'Serie episode delete',
        ]);

        $episode = Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode to remove',
            'page_url' => 'https://example.com/serie-ep-delete/episode-1/see/',
        ]);

        $response = $this->delete(route('series-infos.episodes.destroy', [
            'seriesInfo' => $seriesInfo,
            'episode' => $episode,
        ]));

        $response->assertRedirect(route('series-infos.show', $seriesInfo));
        $this->assertDatabaseMissing('episodes', ['id' => $episode->id]);
    }

    public function test_multiple_episodes_can_be_deleted_in_bulk(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/serie-bulk-delete/episode-1/see/',
            'series_page_url' => 'https://example.com/serie-bulk-delete',
            'title' => 'Serie bulk delete',
        ]);

        $episodeOne = Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode 1',
            'page_url' => 'https://example.com/serie-bulk-delete/episode-1/see/',
        ]);

        $episodeTwo = Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode 2',
            'page_url' => 'https://example.com/serie-bulk-delete/episode-2/see/',
        ]);

        $keptEpisode = Episode::query()->create([
            'series_info_id' => $seriesInfo->id,
            'title' => 'Episode kept',
            'page_url' => 'https://example.com/serie-bulk-delete/episode-3/see/',
        ]);

        $response = $this->delete(route('series-infos.episodes.bulk-destroy', $seriesInfo), [
            'episode_ids' => [$episodeOne->id, $episodeTwo->id],
        ]);

        $response->assertRedirect(route('series-infos.show', $seriesInfo));
        $this->assertDatabaseMissing('episodes', ['id' => $episodeOne->id]);
        $this->assertDatabaseMissing('episodes', ['id' => $episodeTwo->id]);
        $this->assertDatabaseHas('episodes', ['id' => $keptEpisode->id]);
    }

    public function test_bulk_delete_requires_at_least_one_episode_selection(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/serie-bulk-validation/episode-1/see/',
            'series_page_url' => 'https://example.com/serie-bulk-validation',
            'title' => 'Serie bulk validation',
        ]);

        $response = $this
            ->from(route('series-infos.show', $seriesInfo))
            ->delete(route('series-infos.episodes.bulk-destroy', $seriesInfo), [
                'episode_ids' => [],
            ]);

        $response->assertRedirect(route('series-infos.show', $seriesInfo));
        $response->assertSessionHasErrors(['episode_ids']);
    }
}
