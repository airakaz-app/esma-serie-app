<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\SeriesInfo;
use App\Services\Episodes\EpisodeSyncService;
use App\Services\Scraper\EpisodeListScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeSyncLogicTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_adds_only_episodes_after_latest_number_and_resets_old_new_flags(): void
    {
        $seriesInfo = SeriesInfo::query()->create([
            'source_episode_page_url' => 'https://example.com/watch/serie-x/episode-1/see/',
            'series_page_url' => 'https://example.com/watch/serie-x/',
            'title' => 'Serie X',
        ]);

        foreach (range(5, 9) as $number) {
            Episode::query()->create([
                'series_info_id' => $seriesInfo->id,
                'title' => 'Episode '.$number,
                'page_url' => "https://example.com/watch/serie-x/ep-{$number}/see/",
                'episode_number' => $number,
                'is_new' => $number === 9,
            ]);
        }

        $scraper = $this->createMock(EpisodeListScraper::class);
        $scraper->method('scrape')->willReturn([
            ['title' => 'Episode 5', 'page_url' => 'https://example.com/watch/serie-x/ep-5/see/', 'episode_number' => 5, 'image_url' => null],
            ['title' => 'Episode 9', 'page_url' => 'https://example.com/watch/serie-x/ep-9/see/', 'episode_number' => 9, 'image_url' => null],
            ['title' => 'Episode 10', 'page_url' => 'https://example.com/watch/serie-x/ep-10/see/', 'episode_number' => 10, 'image_url' => null],
            ['title' => 'Episode 11', 'page_url' => 'https://example.com/watch/serie-x/ep-11/see/', 'episode_number' => 11, 'image_url' => null],
        ]);

        $service = new EpisodeSyncService($scraper);
        $result = $service->syncAllSeries('test');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['new_episodes_count']);

        $this->assertDatabaseHas('episodes', [
            'series_info_id' => $seriesInfo->id,
            'episode_number' => 10,
            'is_new' => true,
            'status' => Episode::STATUS_DONE,
        ]);
        $this->assertDatabaseHas('episodes', [
            'series_info_id' => $seriesInfo->id,
            'episode_number' => 11,
            'is_new' => true,
        ]);
        $this->assertDatabaseHas('episodes', [
            'series_info_id' => $seriesInfo->id,
            'episode_number' => 9,
            'is_new' => false,
        ]);
    }
}
