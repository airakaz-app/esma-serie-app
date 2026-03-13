<?php

namespace App\Services\Scraper;

class EpisodeListScraper
{
    public function __construct(private readonly HtmlFetcher $fetcher)
    {
    }

    /**
     * @return array<int, array{title:string,page_url:string}>
     */
    public function scrape(string $listUrl): array
    {
        $crawler = $this->fetcher->fetch($listUrl);

        $episodes = [];

        foreach ($crawler->filter('article.postEp') as $node) {
            $item = $crawler->createSubCrawler($node);
            $anchor = $item->filter('a[href]');
            $titleNode = $item->filter('div.title');

            if ($anchor->count() === 0 || $titleNode->count() === 0) {
                continue;
            }

            $pageUrl = trim((string) $anchor->first()->attr('href'));
            if ($pageUrl === '') {
                continue;
            }

            $episodes[] = [
                'title' => trim($titleNode->first()->text('')),
                'page_url' => rtrim($pageUrl, '/').'/see/',
            ];
        }

        return $episodes;
    }
}
