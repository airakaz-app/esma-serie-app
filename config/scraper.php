<?php

return [

    /*
    |--------------------------------------------------------------------------
    | URL de base du site source (utilisé pour la recherche)
    |--------------------------------------------------------------------------
    */
    'source_base_url' => env('SCRAPER_SOURCE_BASE_URL', 'https://n.esheaq.onl'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts HTTP
    |--------------------------------------------------------------------------
    */
    'http_timeout' => (int) env('SCRAPER_HTTP_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Nombre maximum de tentatives par serveur
    |--------------------------------------------------------------------------
    */
    'max_retries' => (int) env('SCRAPER_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Hôtes de serveurs autorisés
    |--------------------------------------------------------------------------
    */
    'allowed_hosts' => array_filter(array_map('trim', explode(',', (string) env('SCRAPER_ALLOWED_HOSTS', 'vidspeed,vidoba,vdesk')))),

    /*
    |--------------------------------------------------------------------------
    | Priorité des hôtes (ordre de tentative : le premier est essayé en premier)
    |--------------------------------------------------------------------------
    */
    'host_priority' => array_filter(array_map('trim', explode(',', (string) env('SCRAPER_HOST_PRIORITY', 'vidspeed,vidoba,vdesk')))),


];
