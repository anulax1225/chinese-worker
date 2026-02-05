<?php

use App\DTOs\Search\SearchQuery;
use App\Exceptions\SearchException;
use App\Services\Search\SearXNGClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

describe('SearXNGClient', function () {
    beforeEach(function () {
        Config::set('search.searxng', [
            'base_url' => 'http://searxng:8080',
            'timeout' => 10,
            'engines' => ['google', 'bing', 'duckduckgo'],
            'safe_search' => 1,
        ]);

        $this->client = new SearXNGClient(
            baseUrl: 'http://searxng:8080',
            timeout: 10,
            defaultEngines: ['google', 'bing', 'duckduckgo'],
            safeSearch: 1,
        );
    });

    test('search returns results on success', function () {
        Http::fake([
            'searxng:8080/search*' => Http::response([
                'results' => [
                    [
                        'title' => 'Laravel - The PHP Framework',
                        'url' => 'https://laravel.com',
                        'content' => 'Laravel is a web application framework',
                        'engine' => 'google',
                    ],
                    [
                        'title' => 'Laravel Documentation',
                        'url' => 'https://laravel.com/docs',
                        'content' => 'Laravel documentation',
                        'engine' => 'bing',
                    ],
                ],
            ]),
        ]);

        $query = new SearchQuery('laravel tutorial');
        $results = $this->client->search($query);

        expect($results)->toBeArray()
            ->and($results)->toHaveCount(2)
            ->and($results[0]['title'])->toBe('Laravel - The PHP Framework')
            ->and($results[1]['url'])->toBe('https://laravel.com/docs');
    });

    test('search sends correct query parameters', function () {
        Http::fake([
            'http://searxng:8080/*' => Http::response(['results' => []]),
        ]);

        $query = new SearchQuery(
            query: 'laravel tutorial',
            engines: ['google'],
            language: 'en',
            timeRange: 'week',
        );

        $this->client->search($query);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'q=')
                && str_contains($url, 'format=json')
                && str_contains($url, 'engines=google')
                && str_contains($url, 'safesearch=1')
                && str_contains($url, 'language=en')
                && str_contains($url, 'time_range=week');
        });
    });

    test('search uses default engines when query engines is null', function () {
        Http::fake([
            'searxng:8080/*' => Http::response([
                'results' => [
                    ['title' => 'Test', 'url' => 'https://test.com', 'content' => 'Test'],
                ],
            ]),
        ]);

        $query = new SearchQuery('laravel tutorial', engines: null);
        $results = $this->client->search($query);

        // Verify search succeeded (uses default engines internally)
        expect($results)->toBeArray()
            ->and($results)->toHaveCount(1);
    });

    test('search uses custom engines when provided', function () {
        Http::fake([
            'searxng:8080/search*' => Http::response(['results' => []]),
        ]);

        $query = new SearchQuery('laravel tutorial', engines: ['wikipedia']);
        $this->client->search($query);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'engines=wikipedia');
        });
    });

    test('search throws SearchException for empty query', function () {
        $query = new SearchQuery('');

        $this->client->search($query);
    })->throws(SearchException::class, 'Invalid search query');

    test('search throws SearchException on HTTP error', function () {
        Http::fake([
            'searxng:8080/search*' => Http::response('Server Error', 500),
        ]);

        $query = new SearchQuery('laravel tutorial');

        $this->client->search($query);
    })->throws(SearchException::class, 'Invalid search response: HTTP status: 500');

    test('search throws SearchException on 404', function () {
        Http::fake([
            'searxng:8080/search*' => Http::response('Not Found', 404),
        ]);

        $query = new SearchQuery('laravel tutorial');

        $this->client->search($query);
    })->throws(SearchException::class, 'Invalid search response: HTTP status: 404');

    test('search throws SearchException on connection failure', function () {
        Http::fake([
            'searxng:8080/search*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        $query = new SearchQuery('laravel tutorial');

        $this->client->search($query);
    })->throws(SearchException::class, 'Failed to connect to search service');

    test('search returns empty array when no results', function () {
        Http::fake([
            'searxng:8080/search*' => Http::response(['results' => []]),
        ]);

        $query = new SearchQuery('xyznonexistentquery123');
        $results = $this->client->search($query);

        expect($results)->toBeArray()
            ->and($results)->toBeEmpty();
    });

    test('search returns empty array when results key missing', function () {
        Http::fake([
            'searxng:8080/search*' => Http::response(['query' => 'test']),
        ]);

        $query = new SearchQuery('laravel tutorial');
        $results = $this->client->search($query);

        expect($results)->toBeArray()
            ->and($results)->toBeEmpty();
    });

    test('isAvailable returns true when healthz succeeds', function () {
        Http::fake([
            'searxng:8080/healthz' => Http::response('OK', 200),
        ]);

        expect($this->client->isAvailable())->toBeTrue();
    });

    test('isAvailable returns false on HTTP error', function () {
        Http::fake([
            'searxng:8080/healthz' => Http::response('Error', 500),
        ]);

        expect($this->client->isAvailable())->toBeFalse();
    });

    test('isAvailable returns false on connection failure', function () {
        Http::fake([
            'searxng:8080/healthz' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        expect($this->client->isAvailable())->toBeFalse();
    });

    test('getName returns searxng', function () {
        expect($this->client->getName())->toBe('searxng');
    });

    test('fromConfig creates instance from config', function () {
        $client = SearXNGClient::fromConfig();

        expect($client)->toBeInstanceOf(SearXNGClient::class)
            ->and($client->getName())->toBe('searxng');
    });
});
