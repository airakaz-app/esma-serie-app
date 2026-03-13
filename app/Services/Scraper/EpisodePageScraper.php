<?php

namespace App\Services\Scraper;

class EpisodePageScraper
{
    public function __construct(
        private readonly HtmlFetcher $fetcher,
        private readonly UrlHelper $urlHelper,
    ) {
    }

    /**
     * @return array<int, array{server_name:?string,host:?string,server_page_url:string}>
     */
    public function extractServers(string $episodeUrl): array
    {
        $crawler = $this->fetcher->fetch($episodeUrl);
        $allowedHosts = collect(config('scraper.allowed_hosts', ['vdesk']))
            ->map(fn (string $host): string => mb_strtolower($host))
            ->values();

        $servers = [];

        foreach ($crawler->filter('.serversList li') as $node) {
            $item = $crawler->createSubCrawler($node);
            $dataSrc = trim((string) ($node->attributes?->getNamedItem('data-src')?->nodeValue ?? ''));
            $host = trim($item->filter('em')->first()->text(''));

            if ($dataSrc === '' || ! $allowedHosts->contains(mb_strtolower($host))) {
                continue;
            }

            $servers[] = [
                'server_name' => trim($item->filter('span')->first()->text('')),
                'host' => $host,
                'server_page_url' => $this->urlHelper->absoluteUrl($episodeUrl, $dataSrc),
            ];
        }

        return $servers;
    }

    public function extractIframeUrl(string $serverPageUrl): ?string
    {
        $crawler = $this->fetcher->fetch($serverPageUrl);
        $iframe = $crawler->filter('iframe[src]')->first();

        if ($iframe->count() === 0) {
            $iframe = $crawler->filter('noscript iframe[src]')->first();
        }

        if ($iframe->count() === 0) {
            return null;
        }

        $src = trim((string) $iframe->attr('src'));
        if ($src === '') {
            return null;
        }

        $url = $this->urlHelper->absoluteUrl($serverPageUrl, $src);

        return str_replace('embed-', '', $url);
    }
}
