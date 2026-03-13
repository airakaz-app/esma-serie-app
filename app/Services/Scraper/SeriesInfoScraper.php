<?php

namespace App\Services\Scraper;

class SeriesInfoScraper
{
    public function __construct(private readonly HtmlFetcher $fetcher)
    {
    }

    /**
     * @return array{source_episode_page_url:string,series_page_url:string,title:string,title_url:string,cover_image_url:string,story:string,categories:array<int,array{name:string,url:string}>,actors:array<int,array{name:string,url:string}>}
     */
    public function scrapeFromEpisodeUrl(string $episodePageUrl): array
    {
        $seriesPageUrl = $this->getSeriesPageUrl($episodePageUrl);
        $html = $this->fetcher->fetch($seriesPageUrl);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $container = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' singleSeries ')]")?->item(0);
        if (! $container instanceof \DOMElement) {
            return $this->emptyPayload($episodePageUrl, $seriesPageUrl);
        }

        $infoBlock = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' info ')]", $container)?->item(0);
        if (! $infoBlock instanceof \DOMElement) {
            $infoBlock = $container;
        }

        $titleLink = $xpath->query('.//h1//a', $infoBlock)?->item(0);
        $title = $titleLink instanceof \DOMElement ? $this->sanitizeTitle(trim($titleLink->textContent)) : '';
        $titleUrl = '';

        if ($titleLink instanceof \DOMElement) {
            $href = trim((string) $titleLink->getAttribute('href'));
            if ($href !== '') {
                $titleUrl = $this->resolveUrl($seriesPageUrl, $href);
            }
        }

        $storyNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' story ')]", $infoBlock)?->item(0);
        $story = $storyNode instanceof \DOMElement ? trim(preg_replace('/\s+/', ' ', $storyNode->textContent) ?? '') : '';

        $coverNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' cover ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' img ')]", $container)?->item(0);
        $coverImageUrl = '';
        if ($coverNode instanceof \DOMElement) {
            $coverImageUrl = $this->extractBackgroundImageUrl((string) $coverNode->getAttribute('style'), $seriesPageUrl);
        }

        $categories = [];
        $actors = [];

        $taxNodes = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' tax ')]", $infoBlock);
        if ($taxNodes !== false) {
            foreach ($taxNodes as $taxNode) {
                if (! $taxNode instanceof \DOMElement) {
                    continue;
                }

                $labelNode = $xpath->query('.//span', $taxNode)?->item(0);
                $labelText = $labelNode instanceof \DOMElement ? trim(preg_replace('/\s+/', ' ', $labelNode->textContent) ?? '') : '';

                $links = [];
                $linkNodes = $xpath->query('.//a[@href]', $taxNode);
                if ($linkNodes !== false) {
                    foreach ($linkNodes as $linkNode) {
                        if (! $linkNode instanceof \DOMElement) {
                            continue;
                        }

                        $href = trim((string) $linkNode->getAttribute('href'));
                        $name = trim(preg_replace('/\s+/', ' ', $linkNode->textContent) ?? '');

                        if ($href === '' || $name === '') {
                            continue;
                        }

                        $links[] = [
                            'name' => $name,
                            'url' => $this->resolveUrl($seriesPageUrl, $href),
                        ];
                    }
                }

                $hasCategoryLinks = collect($links)->contains(fn (array $item): bool => str_contains($item['url'], '/category/'));
                $hasActorLinks = collect($links)->contains(fn (array $item): bool => str_contains($item['url'], '/actor/'));

                if ($hasCategoryLinks || str_contains($labelText, 'تصنيفات')) {
                    $categories = $links;
                } elseif ($hasActorLinks || str_contains($labelText, 'الممثلين')) {
                    $actors = $links;
                }
            }
        }

        return [
            'source_episode_page_url' => $episodePageUrl,
            'series_page_url' => $seriesPageUrl,
            'title' => $title,
            'title_url' => $titleUrl,
            'cover_image_url' => $coverImageUrl,
            'story' => $story,
            'categories' => $categories,
            'actors' => $actors,
        ];
    }

    private function getSeriesPageUrl(string $episodePageUrl): string
    {
        $url = rtrim($episodePageUrl, '/');
        if (str_ends_with($url, '/see')) {
            $url = substr($url, 0, -4);
        }

        return rtrim($url, '/').'/';
    }

    private function extractBackgroundImageUrl(string $style, string $baseUrl): string
    {
        if (preg_match('/background-image\s*:\s*url\((["\']?)(.*?)\1\)/i', $style, $matches) !== 1) {
            return '';
        }

        $candidate = trim((string) ($matches[2] ?? ''));
        if ($candidate === '') {
            return '';
        }

        return $this->resolveUrl($baseUrl, $candidate);
    }

    private function emptyPayload(string $sourceEpisodePageUrl, string $seriesPageUrl): array
    {
        return [
            'source_episode_page_url' => $sourceEpisodePageUrl,
            'series_page_url' => $seriesPageUrl,
            'title' => '',
            'title_url' => '',
            'cover_image_url' => '',
            'story' => '',
            'categories' => [],
            'actors' => [],
        ];
    }

    private function sanitizeTitle(string $title): string
    {
        return trim(str_replace('الحلقة 1 ', '', $title));
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

        return $scheme.'://'.$host.$port.$baseDir.'/'.$url;
    }
}
