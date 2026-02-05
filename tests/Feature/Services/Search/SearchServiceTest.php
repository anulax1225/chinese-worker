<?php

use App\Contracts\SearchClientInterface;
use App\DTOs\Search\SearchQuery;
use App\DTOs\Search\SearchResult;
use App\DTOs\Search\SearchResultCollection;
use App\Exceptions\SearchException;
use App\Services\Search\SearchCache;
use App\Services\Search\SearchResultNormalizer;
use App\Services\Search\SearchService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

describe('SearchService', function () {
    beforeEach(function () {
        Config::set('search', [
            'driver' => 'searxng',
            'searxng' => [
                'base_url' => 'http://searxng:8080',
                'timeout' => 10,
                'engines' => ['google', 'bing', 'duckduckgo'],
                'safe_search' => 1,
            ],
            'cache' => [
                'enabled' => true,
                'ttl' => 3600,
                'prefix' => 'search:',
                'store' => 'array',
            ],
            'retry' => [
                'times' => 2,
                'sleep_ms' => 100,
            ],
        ]);

        $this->mockResults = [
            [
                'title' => 'Laravel - The PHP Framework',
                'url' => 'https://laravel.com',
                'content' => 'Laravel is a web application framework',
                'engine' => 'google',
                'score' => 0.95,
            ],
            [
                'title' => 'Laravel Documentation',
                'url' => 'https://laravel.com/docs',
                'content' => 'Laravel documentation',
                'engine' => 'bing',
                'score' => 0.85,
            ],
        ];

        $this->normalizer = new SearchResultNormalizer;
    });

    test('search returns results on cache miss', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')->once()->andReturn($this->mockResults);
        $mockClient->shouldReceive('getName')->andReturn('mock');

        $service = new SearchService($mockClient, $this->normalizer);

        $query = new SearchQuery('laravel tutorial');
        $results = $service->search($query);

        expect($results)->toBeInstanceOf(SearchResultCollection::class)
            ->and($results->count())->toBe(2)
            ->and($results->first()->title)->toBe('Laravel - The PHP Framework');
    });

    test('search returns cached results on cache hit', function () {
        $cachedResults = new SearchResultCollection(
            results: [new SearchResult('Cached Result', 'https://cached.com', 'Cached', 'google')],
            query: 'laravel tutorial',
            searchTime: 0.1,
            fromCache: true,
        );

        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldNotReceive('search');

        $mockCache = Mockery::mock(SearchCache::class);
        $mockCache->shouldReceive('get')->once()->andReturn($cachedResults);

        $service = new SearchService($mockClient, $this->normalizer, $mockCache);

        $query = new SearchQuery('laravel tutorial');
        $results = $service->search($query);

        expect($results)->toBe($cachedResults)
            ->and($results->fromCache)->toBeTrue();
    });

    test('search caches results after successful search', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')->once()->andReturn($this->mockResults);
        $mockClient->shouldReceive('getName')->andReturn('mock');

        $mockCache = Mockery::mock(SearchCache::class);
        $mockCache->shouldReceive('get')->once()->andReturn(null);
        $mockCache->shouldReceive('put')->once();

        $service = new SearchService($mockClient, $this->normalizer, $mockCache);

        $query = new SearchQuery('laravel tutorial');
        $results = $service->search($query);

        expect($results->count())->toBe(2);
    });

    test('search does not cache empty results', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')->once()->andReturn([]);
        $mockClient->shouldReceive('getName')->andReturn('mock');

        $mockCache = Mockery::mock(SearchCache::class);
        $mockCache->shouldReceive('get')->once()->andReturn(null);
        $mockCache->shouldNotReceive('put');

        $service = new SearchService($mockClient, $this->normalizer, $mockCache);

        $query = new SearchQuery('nonexistent query xyz');
        $results = $service->search($query);

        expect($results->isEmpty())->toBeTrue();
    });

    test('search throws SearchException for invalid query', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);

        $service = new SearchService($mockClient, $this->normalizer);

        $query = new SearchQuery('');
        $service->search($query);
    })->throws(SearchException::class, 'Invalid search query');

    test('search retries on connection failure', function () {
        $callCount = 0;
        $mockResults = $this->mockResults;

        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')
            ->twice()
            ->andReturnUsing(function () use (&$callCount, $mockResults) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection refused');
                }

                return $mockResults;
            });
        $mockClient->shouldReceive('getName')->andReturn('mock');

        $service = new SearchService($mockClient, $this->normalizer);

        $query = new SearchQuery('laravel tutorial');
        $results = $service->search($query);

        expect($results->count())->toBe(2);
    });

    test('search throws after retries exhausted', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')
            ->times(2)
            ->andThrow(new ConnectionException('Connection refused'));
        $mockClient->shouldReceive('getName')->andReturn('mock');

        $service = new SearchService($mockClient, $this->normalizer);

        $query = new SearchQuery('laravel tutorial');
        $service->search($query);
    })->throws(SearchException::class, 'mock service is unavailable');

    test('search re-throws SearchException directly', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')
            ->once()
            ->andThrow(SearchException::invalidQuery('Custom reason'));

        $service = new SearchService($mockClient, $this->normalizer);

        $query = new SearchQuery('test');
        $service->search($query);
    })->throws(SearchException::class, 'Invalid search query: Custom reason');

    test('search logs metrics on success', function () {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Search completed'
                    && $context['query'] === 'laravel tutorial'
                    && isset($context['results_count'])
                    && isset($context['duration_ms'])
                    && $context['cache_hit'] === false
                    && $context['client'] === 'mock';
            });

        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')->once()->andReturn($this->mockResults);
        $mockClient->shouldReceive('getName')->andReturn('mock');

        $service = new SearchService($mockClient, $this->normalizer);

        $query = new SearchQuery('laravel tutorial');
        $service->search($query);
    });

    test('search logs cache hit', function () {
        $cachedResults = new SearchResultCollection(
            results: [new SearchResult('Cached', 'https://example.com', 'Cached', 'google')],
            query: 'laravel tutorial',
            searchTime: 0.1,
            fromCache: true,
        );

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Search cache hit'
                    && $context['query'] === 'laravel tutorial';
            });

        $mockClient = Mockery::mock(SearchClientInterface::class);

        $mockCache = Mockery::mock(SearchCache::class);
        $mockCache->shouldReceive('get')->once()->andReturn($cachedResults);

        $service = new SearchService($mockClient, $this->normalizer, $mockCache);

        $query = new SearchQuery('laravel tutorial');
        $service->search($query);
    });

    test('isAvailable delegates to client', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('isAvailable')->once()->andReturn(true);

        $service = new SearchService($mockClient, $this->normalizer);

        expect($service->isAvailable())->toBeTrue();
    });

    test('isAvailable returns false when client unavailable', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('isAvailable')->once()->andReturn(false);

        $service = new SearchService($mockClient, $this->normalizer);

        expect($service->isAvailable())->toBeFalse();
    });

    test('getClientName returns client name', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('getName')->once()->andReturn('searxng');

        $service = new SearchService($mockClient, $this->normalizer);

        expect($service->getClientName())->toBe('searxng');
    });

    test('make factory creates instance', function () {
        $service = SearchService::make();

        expect($service)->toBeInstanceOf(SearchService::class);
    });

    test('service is registered as singleton', function () {
        $service1 = app(SearchService::class);
        $service2 = app(SearchService::class);

        expect($service1)->toBe($service2);
    });

    test('search works without cache', function () {
        $mockClient = Mockery::mock(SearchClientInterface::class);
        $mockClient->shouldReceive('search')->once()->andReturn($this->mockResults);
        $mockClient->shouldReceive('getName')->andReturn('mock');

        $service = new SearchService($mockClient, $this->normalizer, null);

        $query = new SearchQuery('laravel tutorial');
        $results = $service->search($query);

        expect($results->count())->toBe(2);
    });
});
