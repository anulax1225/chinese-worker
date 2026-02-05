<?php

use App\DTOs\Search\SearchQuery;

describe('SearchQuery', function () {
    test('generates deterministic cache key', function () {
        $query1 = new SearchQuery('laravel tutorial');
        $query2 = new SearchQuery('laravel tutorial');

        expect($query1->cacheKey())->toBe($query2->cacheKey());
    });

    test('cache key is case-insensitive', function () {
        $query1 = new SearchQuery('Laravel Tutorial');
        $query2 = new SearchQuery('laravel tutorial');

        expect($query1->cacheKey())->toBe($query2->cacheKey());
    });

    test('cache key trims whitespace', function () {
        $query1 = new SearchQuery('  laravel tutorial  ');
        $query2 = new SearchQuery('laravel tutorial');

        expect($query1->cacheKey())->toBe($query2->cacheKey());
    });

    test('same query with different max_results has different cache key', function () {
        $query1 = new SearchQuery('laravel tutorial', maxResults: 5);
        $query2 = new SearchQuery('laravel tutorial', maxResults: 10);

        expect($query1->cacheKey())->not->toBe($query2->cacheKey());
    });

    test('same query with different engines has different cache key', function () {
        $query1 = new SearchQuery('laravel tutorial', engines: ['google']);
        $query2 = new SearchQuery('laravel tutorial', engines: ['google', 'bing']);

        expect($query1->cacheKey())->not->toBe($query2->cacheKey());
    });

    test('same query with different language has different cache key', function () {
        $query1 = new SearchQuery('laravel tutorial', language: 'en');
        $query2 = new SearchQuery('laravel tutorial', language: 'fr');

        expect($query1->cacheKey())->not->toBe($query2->cacheKey());
    });

    test('isValid returns true for non-empty query', function () {
        $query = new SearchQuery('laravel tutorial');

        expect($query->isValid())->toBeTrue();
    });

    test('isValid returns false for empty query', function () {
        $query = new SearchQuery('');

        expect($query->isValid())->toBeFalse();
    });

    test('isValid returns false for whitespace-only query', function () {
        $query = new SearchQuery('   ');

        expect($query->isValid())->toBeFalse();
    });

    test('toArray returns all properties', function () {
        $query = new SearchQuery(
            query: 'laravel tutorial',
            maxResults: 10,
            engines: ['google', 'bing'],
            language: 'en',
            timeRange: 'week',
        );

        expect($query->toArray())->toBe([
            'query' => 'laravel tutorial',
            'max_results' => 10,
            'engines' => ['google', 'bing'],
            'language' => 'en',
            'time_range' => 'week',
        ]);
    });

    test('toArray includes null for optional fields', function () {
        $query = new SearchQuery('laravel tutorial');

        expect($query->toArray())->toBe([
            'query' => 'laravel tutorial',
            'max_results' => 5,
            'engines' => null,
            'language' => null,
            'time_range' => null,
        ]);
    });

    test('has correct default values', function () {
        $query = new SearchQuery('test');

        expect($query->maxResults)->toBe(5)
            ->and($query->engines)->toBeNull()
            ->and($query->language)->toBeNull()
            ->and($query->timeRange)->toBeNull();
    });
});
