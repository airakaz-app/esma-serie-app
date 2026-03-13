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
        $html = $this->fetcher->fetch($episodeUrl);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        $xpath = new \DOMXPath($dom);
        $allowedHosts = collect(config('scraper.allowed_hosts', ['vdesk']))
            ->map(fn (string $host): string => mb_strtolower($host))
            ->values();

        $servers = [];
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' serversList ')]//li");

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $dataSrc = trim((string) $node->getAttribute('data-src'));
            $host = trim((string) ($xpath->query('.//em', $node)?->item(0)?->textContent ?? ''));

            if ($dataSrc === '' || ! $allowedHosts->contains(mb_strtolower($host))) {
                continue;
            }

            $serverName = trim((string) ($xpath->query('.//span', $node)?->item(0)?->textContent ?? ''));

            $servers[] = [
                'server_name' => $serverName,
                'host' => $host,
                'server_page_url' => $this->urlHelper->absoluteUrl($episodeUrl, $dataSrc),
            ];
        }

        return $servers;
    }

    public function extractIframeUrl(string $serverPageUrl): ?string
    {
        $html = $this->fetcher->fetch($serverPageUrl);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        $xpath = new \DOMXPath($dom);

        $iframe = $xpath->query('//iframe[@src]')?->item(0);
        if (! $iframe instanceof \DOMElement) {
            $iframe = $xpath->query('//noscript//iframe[@src]')?->item(0);
        }

        if (! $iframe instanceof \DOMElement) {
            return null;
        }

        $src = trim((string) $iframe->getAttribute('src'));
        if ($src === '') {
            return null;
        }

        $url = $this->urlHelper->absoluteUrl($serverPageUrl, $src);

        return str_replace('embed-', '', $url);
    }
}
