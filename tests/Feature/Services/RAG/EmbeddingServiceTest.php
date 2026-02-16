<?php

use App\Contracts\AIBackendInterface;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\EmbeddingCache;
use App\Services\RAG\EmbeddingService;
use Illuminate\Support\Facades\Config;

// Use small test embeddings for speed (4 dimensions instead of 1536)
const TEST_EMBEDDING_DIM = 4;

describe('EmbeddingService', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'embedding_model' => 'test-model',
            'embedding_backend' => 'openai',
            'embedding_batch_size' => 100,
            'cache_embeddings' => true,
        ]);

        $this->mockEmbedding = [0.1, 0.2, 0.3, 0.4];
    });

    test('embed generates embedding via backend', function () {
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldReceive('generateEmbeddings')
            ->once()
            ->with(['Test text'], 'test-model')
            ->andReturn([$this->mockEmbedding]);

        $service = new EmbeddingService($mockBackend);
        $result = $service->embed('Test text');

        expect($result)->toBe($this->mockEmbedding)
            ->and($result)->toHaveCount(TEST_EMBEDDING_DIM);
    });

    test('embed uses cache on cache hit', function () {
        EmbeddingCache::factory()->create([
            'content_hash' => hash('sha256', 'Cached text::test-model'),
            'embedding_model' => 'test-model',
            'embedding_raw' => $this->mockEmbedding,
        ]);

        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldNotReceive('generateEmbeddings');

        $service = new EmbeddingService($mockBackend);
        $result = $service->embed('Cached text');

        expect($result)->toBe($this->mockEmbedding);
    });

    test('embed stores to cache on cache miss', function () {
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldReceive('generateEmbeddings')
            ->once()
            ->andReturn([$this->mockEmbedding]);

        $service = new EmbeddingService($mockBackend);
        $service->embed('New text to cache');

        $cached = EmbeddingCache::where('content_hash', hash('sha256', 'New text to cache::test-model'))
            ->first();

        expect($cached)->not->toBeNull()
            ->and($cached->embedding_raw)->toBe($this->mockEmbedding);
    });

    test('embedBatch processes multiple texts', function () {
        $texts = ['Text one', 'Text two'];
        $embeddings = [[0.1, 0.2, 0.3, 0.4], [0.5, 0.6, 0.7, 0.8]];

        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldReceive('generateEmbeddings')
            ->once()
            ->with($texts, 'test-model')
            ->andReturn($embeddings);

        $service = new EmbeddingService($mockBackend);
        $results = $service->embedBatch($texts);

        expect($results)->toHaveCount(2)
            ->and($results[0])->toBe($embeddings[0])
            ->and($results[1])->toBe($embeddings[1]);
    });

    test('embedChunks updates DocumentChunk models', function () {
        $document = Document::factory()->create();
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->needsEmbedding()
            ->create();

        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldReceive('generateEmbeddings')
            ->once()
            ->andReturn([$this->mockEmbedding]);

        $service = new EmbeddingService($mockBackend);
        $service->embedChunks(collect([$chunk]));

        $chunk->refresh();

        expect($chunk->embedding_raw)->toBe($this->mockEmbedding)
            ->and($chunk->embedding_model)->toBe('test-model')
            ->and($chunk->embedding_generated_at)->not->toBeNull();
    });

    test('embedChunks skips empty collection', function () {
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldNotReceive('generateEmbeddings');

        $service = new EmbeddingService($mockBackend);
        $service->embedChunks(collect());

        expect(true)->toBeTrue();
    });

    test('generateSparseEmbedding creates term frequencies', function () {
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $service = new EmbeddingService($mockBackend);

        $sparse = $service->generateSparseEmbedding('Laravel is great. Laravel is awesome.');

        expect($sparse)->toBeArray()
            ->and($sparse)->toHaveKey('laravel')
            ->and($sparse['laravel'])->toBe(1.0);
    });

    test('generateSparseEmbedding removes stop words', function () {
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $service = new EmbeddingService($mockBackend);

        $sparse = $service->generateSparseEmbedding('The quick fox');

        expect($sparse)->not->toHaveKey('the')
            ->and($sparse)->toHaveKey('quick');
    });

    test('getEmbeddingDimensions returns backend dimensions', function () {
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldReceive('getEmbeddingDimensions')
            ->once()
            ->with('test-model')
            ->andReturn(TEST_EMBEDDING_DIM);

        $service = new EmbeddingService($mockBackend);
        $dimensions = $service->getEmbeddingDimensions();

        expect($dimensions)->toBe(TEST_EMBEDDING_DIM);
    });
});
