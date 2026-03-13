<?php

return [
    'list_page_url' => env('SCRAPER_LIST_PAGE_URL', ''),
    'http_timeout' => (int) env('SCRAPER_HTTP_TIMEOUT', 20),
    'browser_timeout' => (int) env('SCRAPER_BROWSER_TIMEOUT', 30),
    'max_retries' => (int) env('SCRAPER_MAX_RETRIES', 3),
    'headless' => (bool) env('SCRAPER_HEADLESS', true),
    'webdriver_url' => env('SCRAPER_WEBDRIVER_URL', 'http://127.0.0.1:9515'),
    'webdriver_autostart' => (bool) env('SCRAPER_WEBDRIVER_AUTOSTART', false),
    'webdriver_binary' => env('SCRAPER_WEBDRIVER_BINARY', 'chromedriver'),
    'webdriver_boot_timeout' => (int) env('SCRAPER_WEBDRIVER_BOOT_TIMEOUT', 8),
    'allowed_hosts' => array_filter(array_map('trim', explode(',', (string) env('SCRAPER_ALLOWED_HOSTS', 'vdesk')))),
];
