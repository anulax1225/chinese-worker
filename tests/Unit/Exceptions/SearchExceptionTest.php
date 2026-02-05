<?php

use App\Exceptions\SearchException;

describe('SearchException', function () {
    test('extends RuntimeException', function () {
        $exception = new SearchException('Test message');

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });

    test('has default message', function () {
        $exception = new SearchException;

        expect($exception->getMessage())->toBe('Search operation failed');
    });

    test('accepts custom message', function () {
        $exception = new SearchException('Custom error message');

        expect($exception->getMessage())->toBe('Custom error message');
    });

    test('accepts code and previous exception', function () {
        $previous = new Exception('Previous');
        $exception = new SearchException('Test', 500, $previous);

        expect($exception->getCode())->toBe(500)
            ->and($exception->getPrevious())->toBe($previous);
    });

    test('timeout factory creates message with seconds', function () {
        $exception = SearchException::timeout(30);

        expect($exception->getMessage())->toBe('Search request timed out after 30 seconds');
    });

    test('timeout factory creates generic message when no seconds', function () {
        $exception = SearchException::timeout();

        expect($exception->getMessage())->toBe('Search request timed out');
    });

    test('timeout factory creates generic message when zero seconds', function () {
        $exception = SearchException::timeout(0);

        expect($exception->getMessage())->toBe('Search request timed out');
    });

    test('unavailable factory includes service name', function () {
        $exception = SearchException::unavailable('SearXNG');

        expect($exception->getMessage())->toBe('SearXNG service is unavailable');
    });

    test('unavailable factory uses default service name', function () {
        $exception = SearchException::unavailable();

        expect($exception->getMessage())->toBe('Search service is unavailable');
    });

    test('invalidQuery factory includes reason', function () {
        $exception = SearchException::invalidQuery('Query too short');

        expect($exception->getMessage())->toBe('Invalid search query: Query too short');
    });

    test('invalidQuery factory uses default reason', function () {
        $exception = SearchException::invalidQuery();

        expect($exception->getMessage())->toBe('Invalid search query: Query cannot be empty');
    });

    test('connectionFailed factory includes URL', function () {
        $exception = SearchException::connectionFailed('http://searxng:8080');

        expect($exception->getMessage())->toBe('Failed to connect to search service at http://searxng:8080');
    });

    test('connectionFailed factory preserves previous exception', function () {
        $previous = new Exception('Connection refused');
        $exception = SearchException::connectionFailed('http://searxng:8080', $previous);

        expect($exception->getPrevious())->toBe($previous)
            ->and($exception->getPrevious()->getMessage())->toBe('Connection refused');
    });

    test('invalidResponse factory includes reason', function () {
        $exception = SearchException::invalidResponse('Missing results key');

        expect($exception->getMessage())->toBe('Invalid search response: Missing results key');
    });

    test('invalidResponse factory uses default reason', function () {
        $exception = SearchException::invalidResponse();

        expect($exception->getMessage())->toBe('Invalid search response: Unexpected response format');
    });
});
