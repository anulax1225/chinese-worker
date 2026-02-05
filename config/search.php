<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Search Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default search driver that will be used when
    | performing web searches. Currently supported: "searxng"
    |
    */

    'driver' => env('SEARCH_DRIVER', 'searxng'),

    /*
    |--------------------------------------------------------------------------
    | SearXNG Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the SearXNG metasearch engine.
    |
    */

    'searxng' => [
        'base_url' => env('SEARXNG_URL', 'http://searxng:8080'),
        'timeout' => env('SEARXNG_TIMEOUT', 10),
        'engines' => explode(',', env('SEARXNG_ENGINES', 'google,bing,duckduckgo')),
        'format' => 'json',
        'safe_search' => env('SEARXNG_SAFE_SEARCH', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for search results. Caching reduces load
    | on the search service and improves response times for repeated queries.
    |
    */

    'cache' => [
        'enabled' => env('SEARCH_CACHE_ENABLED', true),
        'ttl' => env('SEARCH_CACHE_TTL', 3600), // 1 hour in seconds
        'prefix' => 'search:',
        'store' => env('SEARCH_CACHE_STORE', 'redis'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Search Options
    |--------------------------------------------------------------------------
    |
    | Default options applied to all search queries unless overridden.
    |
    */

    'defaults' => [
        'max_results' => 5,
        'language' => null, // null = auto-detect
        'time_range' => null, // null = all time
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed search requests.
    |
    */

    'retry' => [
        'times' => env('SEARCH_RETRY_TIMES', 2),
        'sleep_ms' => env('SEARCH_RETRY_SLEEP_MS', 500),
    ],
];
