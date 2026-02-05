<?php

use App\DTOs\Search\SearchResult;
use App\DTOs\Search\SearchResultCollection;

describe('SearchResultCollection', function () {
    beforeEach(function () {
        $this->results = [
            new SearchResult('Result 1', 'https://example.com/1', 'Snippet 1', 'google', 0.9),
            new SearchResult('Result 2', 'https://example.com/2', 'Snippet 2', 'bing', 0.8),
            new SearchResult('Result 3', 'https://example.com/3', 'Snippet 3', 'google', 0.7),
        ];
    });

    test('count returns number of results', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        expect($collection->count())->toBe(3)
            ->and(count($collection))->toBe(3);
    });

    test('isEmpty returns true for empty collection', function () {
        $collection = new SearchResultCollection([], 'test query', 0.5);

        expect($collection->isEmpty())->toBeTrue();
    });

    test('isEmpty returns false for non-empty collection', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        expect($collection->isEmpty())->toBeFalse();
    });

    test('is iterable', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        $count = 0;
        foreach ($collection as $result) {
            expect($result)->toBeInstanceOf(SearchResult::class);
            $count++;
        }

        expect($count)->toBe(3);
    });

    test('all returns all results', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        expect($collection->all())->toBe($this->results);
    });

    test('take returns limited results', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);
        $limited = $collection->take(2);

        expect($limited->count())->toBe(2)
            ->and($limited->all()[0]->title)->toBe('Result 1')
            ->and($limited->all()[1]->title)->toBe('Result 2');
    });

    test('take preserves query and metadata', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5, fromCache: true);
        $limited = $collection->take(2);

        expect($limited->query)->toBe('test query')
            ->and($limited->searchTime)->toBe(0.5)
            ->and($limited->fromCache)->toBeTrue();
    });

    test('filterByEngine filters by engine name', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);
        $filtered = $collection->filterByEngine('google');

        expect($filtered->count())->toBe(2)
            ->and($filtered->all()[0]->title)->toBe('Result 1')
            ->and($filtered->all()[1]->title)->toBe('Result 3');
    });

    test('filterByEngine preserves query and metadata', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5, fromCache: true);
        $filtered = $collection->filterByEngine('google');

        expect($filtered->query)->toBe('test query')
            ->and($filtered->searchTime)->toBe(0.5)
            ->and($filtered->fromCache)->toBeTrue();
    });

    test('filterByEngine returns empty when no matches', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);
        $filtered = $collection->filterByEngine('duckduckgo');

        expect($filtered->isEmpty())->toBeTrue();
    });

    test('first returns first result', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        expect($collection->first())->toBe($this->results[0])
            ->and($collection->first()->title)->toBe('Result 1');
    });

    test('first returns null when empty', function () {
        $collection = new SearchResultCollection([], 'test query', 0.5);

        expect($collection->first())->toBeNull();
    });

    test('toArray returns array of result arrays', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);
        $array = $collection->toArray();

        expect($array)->toBeArray()
            ->and($array)->toHaveCount(3)
            ->and($array[0])->toBe([
                'title' => 'Result 1',
                'url' => 'https://example.com/1',
                'snippet' => 'Snippet 1',
                'engine' => 'google',
                'score' => 0.9,
                'published_date' => null,
            ]);
    });

    test('toJson returns valid JSON', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);
        $json = $collection->toJson();

        expect($json)->toBeString();

        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray()
            ->and($decoded['query'])->toBe('test query')
            ->and($decoded['results'])->toBeArray()
            ->and($decoded['count'])->toBe(3)
            ->and($decoded['search_time_ms'])->toEqual(500.0)
            ->and($decoded['from_cache'])->toBeFalse();
    });

    test('toJson includes fromCache flag', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5, fromCache: true);
        $decoded = json_decode($collection->toJson(), true);

        expect($decoded['from_cache'])->toBeTrue();
    });

    test('withCacheFlag sets fromCache to true', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5, fromCache: false);
        $cached = $collection->withCacheFlag();

        expect($cached->fromCache)->toBeTrue()
            ->and($collection->fromCache)->toBeFalse();
    });

    test('withCacheFlag preserves all other data', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);
        $cached = $collection->withCacheFlag();

        expect($cached->query)->toBe('test query')
            ->and($cached->searchTime)->toBe(0.5)
            ->and($cached->count())->toBe(3);
    });

    test('has readonly query property', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        expect($collection->query)->toBe('test query');
    });

    test('has readonly searchTime property', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        expect($collection->searchTime)->toBe(0.5);
    });

    test('has readonly fromCache property', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5, fromCache: true);

        expect($collection->fromCache)->toBeTrue();
    });

    test('fromCache defaults to false', function () {
        $collection = new SearchResultCollection($this->results, 'test query', 0.5);

        expect($collection->fromCache)->toBeFalse();
    });
});
