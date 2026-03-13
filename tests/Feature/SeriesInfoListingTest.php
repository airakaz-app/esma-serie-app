<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\EpisodeServer;
use App\Models\SeriesInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        $response = $this->postJson(route('series-infos.scrape'), [
            'list_page_url' => 'https://example.com/series',
        ]);

        $response->assertOk();
        $response->assertJsonPath('started', true);
        $this->assertIsString($response->json('trackingKey'));
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
        ], now()->addMinutes(5));

        $response = $this->getJson(route('series-infos.scrape-status', 'test-key'));

        $response->assertOk();
        $response->assertJsonPath('state', 'running');
        $response->assertJsonPath('progressPercent', 33);
        $response->assertJsonPath('seriesInfoId', 11);
    }

    public function test_series_infos_page_scrape_requires_valid_url(): void
    {
        $response = $this->postJson(route('series-infos.scrape'), [
            'list_page_url' => 'invalid-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['list_page_url']);
    }
}
