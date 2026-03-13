<?php

namespace App\Services\Scraper;

class EpisodeListScraper
{
    public function __construct(private readonly HtmlFetcher $fetcher)
    {
    }

    /**
     * @return array<int, array{title:string,page_url:string,episode_number:?int,image_url:?string}>
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

        $articles = iterator_to_array($nodes);
        $articles = array_reverse($articles);

        foreach ($articles as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $anchor = $xpath->query('.//a[@href]', $node)?->item(0);
            $titleNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' title ')]", $node)?->item(0);

            if (! $anchor instanceof \DOMElement || ! $titleNode instanceof \DOMElement) {
                continue;
            }

            $pageUrl = trim((string) $anchor->getAttribute('href'));
            if ($pageUrl === '') {
                continue;
            }

            $title = trim($titleNode->textContent);
            $anchorTitle = trim((string) $anchor->getAttribute('title'));

            $imageUrl = $this->extractImageUrl($xpath, $node, $listUrl);
            $episodeNumber = $this->extractEpisodeNumber($xpath, $node);

            $episodes[] = [
                'title' => $title !== '' ? $title : $anchorTitle,
                'page_url' => $this->normalizeEpisodePageUrl($listUrl, $pageUrl),
                'episode_number' => $episodeNumber,
                'image_url' => $imageUrl,
            ];
        }

        return $episodes;
    }

    private function normalizeEpisodePageUrl(string $baseUrl, string $pageUrl): string
    {
        $resolvedUrl = $this->resolveUrl($baseUrl, $pageUrl);

        return rtrim($resolvedUrl, '/').'/see/';
    }

    private function extractImageUrl(\DOMXPath $xpath, \DOMElement $node, string $baseUrl): ?string
    {
        $imageNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' poster ')]//img", $node)?->item(0);
        if (! $imageNode instanceof \DOMElement) {
            return null;
        }

        foreach (['src', 'data-src', 'data-lazy-src', 'data-original'] as $attribute) {
            $candidate = trim((string) $imageNode->getAttribute($attribute));
            if ($candidate !== '') {
                return $this->resolveUrl($baseUrl, $candidate);
            }
        }

        return null;
    }

    private function extractEpisodeNumber(\DOMXPath $xpath, \DOMElement $node): ?int
    {
        $spans = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' episodeNum ')]//span", $node);
        if ($spans === false || $spans->length === 0) {
            return null;
        }

        for ($index = $spans->length - 1; $index >= 0; $index--) {
            $span = $spans->item($index);
            if (! $span instanceof \DOMElement) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', trim($span->textContent));
            if ($digits !== null && $digits !== '') {
                return (int) $digits;
            }
        }

        return null;
    }

    private function resolveUrl(string $baseUrl, string $url): string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url) === 1) {
            return $url;
        }

        $baseParts = parse_url($baseUrl);
        if (! is_array($baseParts) || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return $url;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$port.$url;
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseDir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        if ($baseDir === '') {
            $baseDir = '';
        }

        return $scheme.'://'.$host.$port.$baseDir.'/'.$url;
    }
}
