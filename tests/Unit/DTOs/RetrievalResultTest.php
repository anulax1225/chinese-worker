<?php

use App\DTOs\RetrievalResult;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

describe('RetrievalResult', function () {
    test('creates instance with all properties', function () {
        $chunks = collect([
            (object) ['id' => 1, 'content' => 'Chunk 1'],
            (object) ['id' => 2, 'content' => 'Chunk 2'],
        ]);
        $scores = [1 => 0.95, 2 => 0.87];

        $result = new RetrievalResult(
            chunks: $chunks,
            strategy: 'hybrid',
            scores: $scores,
            executionTimeMs: 125.5,
        );

        expect($result->chunks)->toBe($chunks)
            ->and($result->strategy)->toBe('hybrid')
            ->and($result->scores)->toBe($scores)
            ->and($result->executionTimeMs)->toBe(125.5);
    });

    test('creates instance with minimal properties', function () {
        $chunks = collect();

        $result = new RetrievalResult(
            chunks: $chunks,
            strategy: 'dense',
        );

        expect($result->chunks)->toBe($chunks)
            ->and($result->strategy)->toBe('dense')
            ->and($result->scores)->toBe([])
            ->and($result->executionTimeMs)->toBe(0.0);
    });

    test('count returns number of chunks', function () {
        $chunks = collect([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);

        $result = new RetrievalResult(chunks: $chunks, strategy: 'dense');

        expect($result->count())->toBe(3);
    });

    test('count returns zero for empty collection', function () {
        $result = new RetrievalResult(chunks: collect(), strategy: 'dense');

        expect($result->count())->toBe(0);
    });

    test('hasChunks returns true when chunks exist', function () {
        $chunks = collect([(object) ['id' => 1]]);

        $result = new RetrievalResult(chunks: $chunks, strategy: 'dense');

        expect($result->hasChunks())->toBeTrue();
    });

    test('hasChunks returns false for empty collection', function () {
        $result = new RetrievalResult(chunks: collect(), strategy: 'dense');

        expect($result->hasChunks())->toBeFalse();
    });

    test('averageScore calculates correct average', function () {
        $chunks = collect([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);
        $scores = [1 => 0.9, 2 => 0.8, 3 => 0.7];

        $result = new RetrievalResult(chunks: $chunks, strategy: 'dense', scores: $scores);

        expect($result->averageScore())->toBeGreaterThan(0.79)
            ->and($result->averageScore())->toBeLessThan(0.81);
    });

    test('averageScore returns zero for empty scores', function () {
        $result = new RetrievalResult(chunks: collect(), strategy: 'dense', scores: []);

        expect($result->averageScore())->toBe(0.0);
    });

    test('getScore returns score for specific chunk', function () {
        $scores = [1 => 0.95, 2 => 0.87];

        $result = new RetrievalResult(
            chunks: collect(),
            strategy: 'dense',
            scores: $scores,
        );

        expect($result->getScore(1))->toBe(0.95)
            ->and($result->getScore(2))->toBe(0.87);
    });

    test('getScore returns null for unknown chunk', function () {
        $result = new RetrievalResult(
            chunks: collect(),
            strategy: 'dense',
            scores: [1 => 0.95],
        );

        expect($result->getScore(999))->toBeNull();
    });

    test('withMinScore filters chunks by threshold', function () {
        $chunks = collect([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);
        $scores = [1 => 0.9, 2 => 0.6, 3 => 0.8];

        $result = new RetrievalResult(chunks: $chunks, strategy: 'dense', scores: $scores);
        $filtered = $result->withMinScore(0.75);

        expect($filtered->count())->toBe(2)
            ->and($filtered->scores)->toBe([1 => 0.9, 3 => 0.8]);
    });

    test('withMinScore returns new instance', function () {
        $chunks = collect([(object) ['id' => 1]]);
        $scores = [1 => 0.9];

        $result = new RetrievalResult(chunks: $chunks, strategy: 'dense', scores: $scores);
        $filtered = $result->withMinScore(0.5);

        expect($filtered)->not->toBe($result);
    });

    test('withMinScore preserves strategy and execution time', function () {
        $chunks = collect([(object) ['id' => 1]]);
        $scores = [1 => 0.9];

        $result = new RetrievalResult(
            chunks: $chunks,
            strategy: 'hybrid',
            scores: $scores,
            executionTimeMs: 100.5,
        );
        $filtered = $result->withMinScore(0.5);

        expect($filtered->strategy)->toBe('hybrid')
            ->and($filtered->executionTimeMs)->toBe(100.5);
    });

    test('getChunkIds returns array of chunk IDs', function () {
        $chunks = collect([
            (object) ['id' => 5],
            (object) ['id' => 10],
            (object) ['id' => 15],
        ]);

        $result = new RetrievalResult(chunks: $chunks, strategy: 'dense');

        expect($result->getChunkIds())->toBe([5, 10, 15]);
    });

    test('getChunkIds returns empty array for no chunks', function () {
        $result = new RetrievalResult(chunks: collect(), strategy: 'dense');

        expect($result->getChunkIds())->toBe([]);
    });

    test('empty creates empty result', function () {
        $result = RetrievalResult::empty();

        expect($result->chunks->isEmpty())->toBeTrue()
            ->and($result->strategy)->toBe('none')
            ->and($result->scores)->toBe([])
            ->and($result->executionTimeMs)->toBe(0.0);
    });

    test('empty accepts custom strategy', function () {
        $result = RetrievalResult::empty('fallback');

        expect($result->strategy)->toBe('fallback');
    });

    test('toArray returns correct structure', function () {
        $chunks = collect([
            (object) ['id' => 1],
            (object) ['id' => 2],
        ]);
        $scores = [1 => 0.9, 2 => 0.8];

        $result = new RetrievalResult(
            chunks: $chunks,
            strategy: 'hybrid',
            scores: $scores,
            executionTimeMs: 125.567,
        );

        expect($result->toArray())->toBe([
            'chunk_count' => 2,
            'strategy' => 'hybrid',
            'average_score' => 0.85,
            'execution_time_ms' => 125.57,
            'chunk_ids' => [1, 2],
        ]);
    });

    test('toArray handles empty result', function () {
        $result = RetrievalResult::empty();

        expect($result->toArray())->toBe([
            'chunk_count' => 0,
            'strategy' => 'none',
            'average_score' => 0.0,
            'execution_time_ms' => 0.0,
            'chunk_ids' => [],
        ]);
    });
});
