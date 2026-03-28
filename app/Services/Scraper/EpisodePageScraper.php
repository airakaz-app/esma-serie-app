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
     * Extrait tous les serveurs depuis la page épisode.
     *
     * Chaque serveur inclut désormais l'iframe_url directe (extraite du <noscript>)
     * pour éviter une requête HTTP supplémentaire vers ?emb=true&id=X&serv=Y.
     *
     * @return array<int, array{server_name:?string,host:?string,server_page_url:string,iframe_url:?string}>
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

            // Extraire l'iframe URL directement du <noscript> (évite requête intermédiaire)
            $iframeUrl = $this->extractIframeFromNoscript($xpath, $node, $episodeUrl);

            $servers[] = [
                'server_name' => $serverName,
                'host' => $host,
                'server_page_url' => $this->urlHelper->absoluteUrl($episodeUrl, $dataSrc),
                'iframe_url' => $iframeUrl,
            ];
        }

        return $servers;
    }

    /**
     * Extrait l'URL de l'iframe depuis le <noscript> enfant du <li> serveur.
     */
    private function extractIframeFromNoscript(\DOMXPath $xpath, \DOMElement $node, string $baseUrl): ?string
    {
        // Le contenu <noscript> est stocké en texte brut par DOMDocument,
        // on doit le re-parser pour extraire l'iframe src.
        $noscript = $xpath->query('.//noscript', $node)?->item(0);
        if (! $noscript instanceof \DOMElement) {
            return null;
        }

        $noscriptHtml = $noscript->textContent;
        if ($noscriptHtml === '') {
            // Fallback : sérialiser le contenu enfant
            $innerDoc = new \DOMDocument();
            foreach ($noscript->childNodes as $child) {
                $noscriptHtml .= $noscript->ownerDocument->saveHTML($child);
            }
        }

        if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $noscriptHtml, $m)) {
            $src = trim($m[1]);
            if ($src !== '') {
                return $this->urlHelper->absoluteUrl($baseUrl, $src);
            }
        }

        return null;
    }

    /**
     * Fallback : extrait l'iframe URL en chargeant la page ?emb=true&id=X&serv=Y.
     *
     * Pour vdesk, supprime le préfixe embed- (nécessaire pour le flux method_free).
     * Pour vidspeed/vidoba, conserve l'URL telle quelle.
     */
    public function extractIframeUrl(string $serverPageUrl, string $host = ''): ?string
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

        // Seulement pour vdesk : on enlève embed- pour utiliser le flux method_free
        if (mb_strtolower($host) === 'vdesk') {
            $url = str_replace('embed-', '', $url);
        }

        return $url;
    }
}
