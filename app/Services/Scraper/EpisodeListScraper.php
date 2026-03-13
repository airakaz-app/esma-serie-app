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
        $html = $this->fetcher->fetch($listUrl);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        $xpath = new \DOMXPath($dom);
        $episodes = [];

        $nodes = $xpath->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' postEp ')]");
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            $anchor = $xpath->query('.//a[@href]', $node)?->item(0);
            $titleNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' title ')]", $node)?->item(0);

            if (! $anchor instanceof \DOMElement || ! $titleNode instanceof \DOMElement) {
                continue;
            }

            $pageUrl = trim((string) $anchor->getAttribute('href'));
            if ($pageUrl === '') {
                continue;
            }

            $episodes[] = [
                'title' => trim($titleNode->textContent),
                'page_url' => rtrim($pageUrl, '/').'/see/',
            ];
        }

        return $episodes;
    }
}
