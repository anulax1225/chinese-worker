<?php

use App\DTOs\Search\SearchQuery;
use App\DTOs\Search\SearchResult;
use App\DTOs\Search\SearchResultCollection;
use App\Services\Search\SearchCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

describe('SearchCache', function () {
    beforeEach(function () {
        Config::set('search.cache', [
            'enabled' => true,
            'ttl' => 3600,
            'prefix' => 'search:',
            'store' => 'array',
        ]);

        $this->cacheRepository = Cache::store('array');
        $this->cache = new SearchCache(
            cache: $this->cacheRepository,
            ttl: 3600,
            prefix: 'search:',
        );

        $this->query = new SearchQuery('laravel tutorial');
        $this->results = new SearchResultCollection(
            results: [
                new SearchResult('Result 1', 'https://example.com/1', 'Snippet 1', 'google'),
                new SearchResult('Result 2', 'https://example.com/2', 'Snippet 2', 'bing'),
            ],
            query: 'laravel tutorial',
            searchTime: 0.5,
        );
    });

    test('get returns null when not cached', function () {
        $result = $this->cache->get($this->query);

        expect($result)->toBeNull();
    });

    test('get returns results when cached', function () {
        $this->cache->put($this->query, $this->results);

        $cached = $this->cache->get($this->query);

        expect($cached)->toBeInstanceOf(SearchResultCollection::class)
            ->and($cached->count())->toBe(2)
            ->and($cached->query)->toBe('laravel tutorial')
            ->and($cached->fromCache)->toBeTrue();
    });

    test('get returns null when cache disabled', function () {
        Config::set('search.cache.enabled', false);

        $this->cache->put($this->query, $this->results);
        $cached = $this->cache->get($this->query);

        expect($cached)->toBeNull();
    });

    test('put stores results in cache', function () {
        $this->cache->put($this->query, $this->results);

        $key = 'search:'.$this->query->cacheKey();
        $cached = $this->cacheRepository->get($key);

        expect($cached)->toBeArray()
            ->and($cached['query'])->toBe('laravel tutorial')
            ->and($cached['results'])->toHaveCount(2)
            ->and($cached['search_time'])->toBe(0.5)
            ->and($cached['cached_at'])->toBeInt();
    });

    test('put does nothing when disabled', function () {
        Config::set('search.cache.enabled', false);

        $this->cache->put($this->query, $this->results);

        $key = 'search:'.$this->query->cacheKey();
        $cached = $this->cacheRepository->get($key);

        expect($cached)->toBeNull();
    });

    test('forget removes from cache', function () {
        $this->cache->put($this->query, $this->results);

        $this->cache->forget($this->query);

        $cached = $this->cache->get($this->query);
        expect($cached)->toBeNull();
    });

    test('isEnabled returns true when enabled in config', function () {
        Config::set('search.cache.enabled', true);

        expect($this->cache->isEnabled())->toBeTrue();
    });

    test('isEnabled returns false when disabled in config', function () {
        Config::set('search.cache.enabled', false);

        expect($this->cache->isEnabled())->toBeFalse();
    });

    test('fromConfig creates instance from config', function () {
        $cache = SearchCache::fromConfig();

        expect($cache)->toBeInstanceOf(SearchCache::class);
    });

    test('cached results preserve all result data', function () {
        $resultsWithData = new SearchResultCollection(
            results: [
                new SearchResult(
                    title: 'Laravel Tutorial',
                    url: 'https://laravel.com/docs',
                    snippet: 'Learn Laravel',
                    engine: 'google',
                    score: 0.95,
                    publishedDate: '2024-01-15',
                ),
            ],
            query: 'laravel tutorial',
            searchTime: 0.25,
        );

        $this->cache->put($this->query, $resultsWithData);
        $cached = $this->cache->get($this->query);

        expect($cached->first()->title)->toBe('Laravel Tutorial')
            ->and($cached->first()->url)->toBe('https://laravel.com/docs')
            ->and($cached->first()->snippet)->toBe('Learn Laravel')
            ->and($cached->first()->engine)->toBe('google')
            ->and($cached->first()->score)->toBe(0.95)
            ->and($cached->first()->publishedDate)->toBe('2024-01-15');
    });

    test('same query returns same cached results', function () {
        $this->cache->put($this->query, $this->results);

        $query2 = new SearchQuery('laravel tutorial');
        $cached = $this->cache->get($query2);

        expect($cached)->toBeInstanceOf(SearchResultCollection::class)
            ->and($cached->count())->toBe(2);
    });

    test('different queries have different cache keys', function () {
        $query2 = new SearchQuery('php framework');
        $results2 = new SearchResultCollection(
            results: [new SearchResult('PHP', 'https://php.net', 'PHP', 'google')],
            query: 'php framework',
            searchTime: 0.3,
        );

        $this->cache->put($this->query, $this->results);
        $this->cache->put($query2, $results2);

        $cached1 = $this->cache->get($this->query);
        $cached2 = $this->cache->get($query2);

        expect($cached1->count())->toBe(2)
            ->and($cached2->count())->toBe(1);
    });
});
