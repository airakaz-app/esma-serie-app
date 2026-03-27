<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\Factory;

class HtmlFetcher
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function __construct(private readonly Factory $http) {}

    public function fetch(string $url): string
    {
        return $this->http
            ->withHeaders([
                'User-Agent'      => self::USER_AGENT,
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding' => 'gzip, deflate',
                'DNT'             => '1',
                'Connection'      => 'keep-alive',
            ])
            ->withOptions(['allow_redirects' => ['max' => 10], 'verify' => false])
            ->timeout((int) config('scraper.http_timeout', 20))
            ->get($url)
            ->throw()
            ->body();
    }
}
