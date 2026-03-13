<?php

namespace App\Services\Scraper;

class UrlHelper
{
    public function absoluteUrl(string $baseUrl, string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $baseParts = parse_url($baseUrl);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        if (str_starts_with($path, '//')) {
            return $scheme.':'.$path;
        }

        if (str_starts_with($path, '/')) {
            return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
        }

        $basePath = $baseParts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        $directory = $directory === '' ? '' : $directory;

        return sprintf('%s://%s%s%s/%s', $scheme, $host, $port, $directory, ltrim($path, '/'));
    }
}
