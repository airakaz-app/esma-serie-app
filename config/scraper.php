<?php

return [
    'list_page_url' => env('SCRAPER_LIST_PAGE_URL', ''),
    'http_timeout' => (int) env('SCRAPER_HTTP_TIMEOUT', 20),
    'browser_timeout' => (int) env('SCRAPER_BROWSER_TIMEOUT', 30),
    'max_retries' => (int) env('SCRAPER_MAX_RETRIES', 3),
    'headless' => (bool) env('SCRAPER_HEADLESS', true),
    'browser_strategy' => env('SCRAPER_BROWSER_STRATEGY', 'auto'),
    'python_binary' => env('SCRAPER_PYTHON_BINARY', 'python3'),
    'python_candidates' => array_values(array_filter(array_map('trim', explode(',', (string) env('SCRAPER_PYTHON_CANDIDATES', 'python3,python,py -3,py'))))),
    'python_script' => env('SCRAPER_PYTHON_SCRIPT', 'browser_click.py'),
    'python_timeout' => (int) env('SCRAPER_PYTHON_TIMEOUT', 60),
    'webdriver_url' => env('SCRAPER_WEBDRIVER_URL', 'http://127.0.0.1:9515'),
    'webdriver_autostart' => (bool) env('SCRAPER_WEBDRIVER_AUTOSTART', true),
    'webdriver_binary' => env('SCRAPER_WEBDRIVER_BINARY', 'chromedriver'),
    'webdriver_boot_timeout' => (int) env('SCRAPER_WEBDRIVER_BOOT_TIMEOUT', 8),
    'webdriver_fallback_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('SCRAPER_WEBDRIVER_FALLBACK_URLS', 'http://127.0.0.1:4444,http://127.0.0.1:4444/wd/hub,http://localhost:9515,http://localhost:4444,http://localhost:4444/wd/hub'))))),
    'allowed_hosts' => array_filter(array_map('trim', explode(',', (string) env('SCRAPER_ALLOWED_HOSTS', 'vdesk')))),
];
