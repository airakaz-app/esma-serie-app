<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\SeriesInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
