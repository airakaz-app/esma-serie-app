<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\Factory;

class HtmlFetcher
{
    public function __construct(private readonly Factory $http)
    {
    }

    public function fetch(string $url): string
    {
        return $this->http
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->timeout((int) config('scraper.http_timeout', 20))
            ->get($url)
            ->throw()
            ->body();
    }
}
