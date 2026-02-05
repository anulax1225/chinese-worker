<?php

use App\DTOs\Search\SearchResult;

describe('SearchResult', function () {
    test('creates instance with all properties', function () {
        $result = new SearchResult(
            title: 'Laravel Tutorial',
            url: 'https://laravel.com/docs',
            snippet: 'Learn Laravel framework',
            engine: 'google',
            score: 0.95,
            publishedDate: '2024-01-15',
            metadata: ['category' => 'general'],
        );

        expect($result->title)->toBe('Laravel Tutorial')
            ->and($result->url)->toBe('https://laravel.com/docs')
            ->and($result->snippet)->toBe('Learn Laravel framework')
            ->and($result->engine)->toBe('google')
            ->and($result->score)->toBe(0.95)
            ->and($result->publishedDate)->toBe('2024-01-15')
            ->and($result->metadata)->toBe(['category' => 'general']);
    });

    test('creates instance with minimal properties', function () {
        $result = new SearchResult(
            title: 'Title',
            url: 'https://example.com',
            snippet: 'Description',
        );

        expect($result->title)->toBe('Title')
            ->and($result->url)->toBe('https://example.com')
            ->and($result->snippet)->toBe('Description')
            ->and($result->engine)->toBeNull()
            ->and($result->score)->toBeNull()
            ->and($result->publishedDate)->toBeNull()
            ->and($result->metadata)->toBe([]);
    });

    test('fromSearXNG creates from raw data', function () {
        $data = [
            'title' => 'Laravel Tutorial',
            'url' => 'https://laravel.com/docs',
            'content' => 'Learn Laravel framework',
            'engine' => 'google',
            'score' => 0.95,
            'publishedDate' => '2024-01-15',
            'category' => 'general',
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->title)->toBe('Laravel Tutorial')
            ->and($result->url)->toBe('https://laravel.com/docs')
            ->and($result->snippet)->toBe('Learn Laravel framework')
            ->and($result->engine)->toBe('google')
            ->and($result->score)->toBe(0.95)
            ->and($result->publishedDate)->toBe('2024-01-15')
            ->and($result->metadata)->toBe(['category' => 'general']);
    });

    test('fromSearXNG uses url as title fallback', function () {
        $data = [
            'url' => 'https://example.com/page',
            'content' => 'Description',
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->title)->toBe('https://example.com/page');
    });

    test('fromSearXNG uses empty title when no title or url', function () {
        $data = [
            'content' => 'Description',
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->title)->toBe('');
    });

    test('fromSearXNG extracts content as snippet', function () {
        $data = [
            'title' => 'Title',
            'url' => 'https://example.com',
            'content' => 'This is the content snippet from SearXNG',
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->snippet)->toBe('This is the content snippet from SearXNG');
    });

    test('fromSearXNG handles missing optional fields', function () {
        $data = [
            'title' => 'Title',
            'url' => 'https://example.com',
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->snippet)->toBe('')
            ->and($result->engine)->toBeNull()
            ->and($result->score)->toBeNull()
            ->and($result->publishedDate)->toBeNull();
    });

    test('fromSearXNG extracts metadata with category and thumbnail', function () {
        $data = [
            'title' => 'Title',
            'url' => 'https://example.com',
            'content' => 'Description',
            'category' => 'images',
            'thumbnail' => 'https://example.com/thumb.jpg',
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->metadata)->toBe([
            'category' => 'images',
            'thumbnail' => 'https://example.com/thumb.jpg',
        ]);
    });

    test('fromSearXNG filters out null metadata', function () {
        $data = [
            'title' => 'Title',
            'url' => 'https://example.com',
            'content' => 'Description',
            'category' => 'general',
            // no thumbnail
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->metadata)->toBe(['category' => 'general']);
    });

    test('fromSearXNG converts score to float', function () {
        $data = [
            'title' => 'Title',
            'url' => 'https://example.com',
            'content' => 'Description',
            'score' => '0.85',
        ];

        $result = SearchResult::fromSearXNG($data);

        expect($result->score)->toBe(0.85)
            ->and($result->score)->toBeFloat();
    });

    test('toArray returns correct structure', function () {
        $result = new SearchResult(
            title: 'Laravel Tutorial',
            url: 'https://laravel.com/docs',
            snippet: 'Learn Laravel framework',
            engine: 'google',
            score: 0.95,
            publishedDate: '2024-01-15',
        );

        expect($result->toArray())->toBe([
            'title' => 'Laravel Tutorial',
            'url' => 'https://laravel.com/docs',
            'snippet' => 'Learn Laravel framework',
            'engine' => 'google',
            'score' => 0.95,
            'published_date' => '2024-01-15',
        ]);
    });

    test('toArray includes null values for optional fields', function () {
        $result = new SearchResult(
            title: 'Title',
            url: 'https://example.com',
            snippet: 'Description',
        );

        expect($result->toArray())->toBe([
            'title' => 'Title',
            'url' => 'https://example.com',
            'snippet' => 'Description',
            'engine' => null,
            'score' => null,
            'published_date' => null,
        ]);
    });
});
