<?php

use App\DTOs\Search\SearchQuery;
use App\DTOs\Search\SearchResult;
use App\DTOs\Search\SearchResultCollection;
use App\Services\Search\SearchResultNormalizer;

describe('SearchResultNormalizer', function () {
    beforeEach(function () {
        $this->normalizer = new SearchResultNormalizer;

        $this->rawResults = [
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
                'content' => 'Laravel documentation and guides',
                'engine' => 'bing',
                'score' => 0.85,
            ],
            [
                'title' => 'Laravel Tutorial',
                'url' => 'https://example.com/tutorial',
                'content' => 'Learn Laravel step by step',
                'engine' => 'duckduckgo',
                'score' => 0.75,
            ],
        ];
    });

    test('normalize creates collection from raw results', function () {
        $query = new SearchQuery('laravel tutorial');

        $collection = $this->normalizer->normalize($this->rawResults, $query, 0.5);

        expect($collection)->toBeInstanceOf(SearchResultCollection::class)
            ->and($collection->count())->toBe(3)
            ->and($collection->query)->toBe('laravel tutorial')
            ->and($collection->searchTime)->toBe(0.5)
            ->and($collection->fromCache)->toBeFalse();
    });

    test('normalize respects maxResults limit', function () {
        $query = new SearchQuery('laravel tutorial', maxResults: 2);

        $collection = $this->normalizer->normalize($this->rawResults, $query, 0.5);

        expect($collection->count())->toBe(2)
            ->and($collection->first()->title)->toBe('Laravel - The PHP Framework');
    });

    test('normalize skips items without URL', function () {
        $rawResults = [
            [
                'title' => 'No URL Result',
                'content' => 'This result has no URL',
                'engine' => 'google',
            ],
            [
                'title' => 'Has URL Result',
                'url' => 'https://example.com',
                'content' => 'This result has a URL',
                'engine' => 'bing',
            ],
            [
                'title' => 'Empty URL Result',
                'url' => '',
                'content' => 'This result has empty URL',
                'engine' => 'duckduckgo',
            ],
        ];

        $query = new SearchQuery('test');
        $collection = $this->normalizer->normalize($rawResults, $query, 0.5);

        expect($collection->count())->toBe(1)
            ->and($collection->first()->title)->toBe('Has URL Result');
    });

    test('normalize sets searchTime', function () {
        $query = new SearchQuery('test');
        $collection = $this->normalizer->normalize($this->rawResults, $query, 1.25);

        expect($collection->searchTime)->toBe(1.25);
    });

    test('normalize sets fromCache to false', function () {
        $query = new SearchQuery('test');
        $collection = $this->normalizer->normalize($this->rawResults, $query, 0.5);

        expect($collection->fromCache)->toBeFalse();
    });

    test('normalize preserves result order', function () {
        $query = new SearchQuery('test');
        $collection = $this->normalizer->normalize($this->rawResults, $query, 0.5);

        $results = $collection->all();
        expect($results[0]->title)->toBe('Laravel - The PHP Framework')
            ->and($results[1]->title)->toBe('Laravel Documentation')
            ->and($results[2]->title)->toBe('Laravel Tutorial');
    });

    test('normalize handles empty results', function () {
        $query = new SearchQuery('test');
        $collection = $this->normalizer->normalize([], $query, 0.5);

        expect($collection->isEmpty())->toBeTrue()
            ->and($collection->count())->toBe(0);
    });

    test('normalizeOne creates single SearchResult', function () {
        $rawResult = [
            'title' => 'Laravel',
            'url' => 'https://laravel.com',
            'content' => 'PHP Framework',
            'engine' => 'google',
            'score' => 0.95,
            'publishedDate' => '2024-01-15',
            'category' => 'general',
        ];

        $result = $this->normalizer->normalizeOne($rawResult);

        expect($result)->toBeInstanceOf(SearchResult::class)
            ->and($result->title)->toBe('Laravel')
            ->and($result->url)->toBe('https://laravel.com')
            ->and($result->snippet)->toBe('PHP Framework')
            ->and($result->engine)->toBe('google')
            ->and($result->score)->toBe(0.95)
            ->and($result->publishedDate)->toBe('2024-01-15');
    });

    test('normalizeOne handles minimal data', function () {
        $rawResult = [
            'url' => 'https://example.com',
        ];

        $result = $this->normalizer->normalizeOne($rawResult);

        expect($result)->toBeInstanceOf(SearchResult::class)
            ->and($result->title)->toBe('https://example.com')
            ->and($result->url)->toBe('https://example.com')
            ->and($result->snippet)->toBe('')
            ->and($result->engine)->toBeNull()
            ->and($result->score)->toBeNull();
    });

    test('normalize converts results to SearchResult instances', function () {
        $query = new SearchQuery('test');
        $collection = $this->normalizer->normalize($this->rawResults, $query, 0.5);

        foreach ($collection as $result) {
            expect($result)->toBeInstanceOf(SearchResult::class);
        }
    });
});
