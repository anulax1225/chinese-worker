<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP Request Settings
    |--------------------------------------------------------------------------
    |
    | Configure the HTTP client behavior for fetching web pages.
    |
    */

    'timeout' => env('WEBFETCH_TIMEOUT', 15),

    'max_size' => env('WEBFETCH_MAX_SIZE', 5242880), // 5MB

    'user_agent' => env('WEBFETCH_USER_AGENT', 'ChineseWorker/1.0 (Web Fetch Bot)'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Content Types
    |--------------------------------------------------------------------------
    |
    | Only responses with these content types will be processed.
    | Other content types will return an error.
    |
    */

    'allowed_content_types' => [
        'text/html',
        'text/plain',
        'application/json',
        'application/xml',
        'text/xml',
        'application/xhtml+xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for fetched pages. Caching reduces load
    | on target servers and improves response times for repeated requests.
    |
    */

    'cache' => [
        'enabled' => env('WEBFETCH_CACHE_ENABLED', true),
        'ttl' => env('WEBFETCH_CACHE_TTL', 1800), // 30 minutes
        'prefix' => 'webfetch:',
        'store' => env('WEBFETCH_CACHE_STORE', 'redis'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed fetch requests.
    |
    */

    'retry' => [
        'times' => env('WEBFETCH_RETRY_TIMES', 2),
        'sleep_ms' => env('WEBFETCH_RETRY_SLEEP_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Extraction Settings
    |--------------------------------------------------------------------------
    |
    | Configure how content is extracted from fetched pages.
    |
    */

    'extraction' => [
        'max_text_length' => env('WEBFETCH_MAX_TEXT', 50000),
        'remove_scripts' => true,
        'remove_styles' => true,
        'remove_navigation' => true,
    ],
];
