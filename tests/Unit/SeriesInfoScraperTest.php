<?php

namespace Tests\Unit;

use App\Services\Scraper\HtmlFetcher;
use App\Services\Scraper\SeriesInfoScraper;
use PHPUnit\Framework\TestCase;

class SeriesInfoScraperTest extends TestCase
{
    public function test_it_removes_episode_one_prefix_from_series_title(): void
    {
        $fetcher = $this->createMock(HtmlFetcher::class);
        $fetcher->method('fetch')->willReturn(<<<'HTML'
            <div class="singleSeries">
                <div class="info">
                    <h1><a href="/serie/test">الحلقة 1 اسم المسلسل</a></h1>
                </div>
            </div>
        HTML);

        $scraper = new SeriesInfoScraper($fetcher);

        $result = $scraper->scrapeFromEpisodeUrl('https://example.com/serie/test/episode-1/see/');

        $this->assertSame('اسم المسلسل', $result['title']);
    }
}
