<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\EmbeddingCache;
use App\Services\AI\FakeBackend;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\Writers\DocumentEmbeddingWriter;
use Illuminate\Support\Facades\Config;

defined('TEST_EMBEDDING_DIM') || define('TEST_EMBEDDING_DIM', 4);
defined('TEST_EMBEDDING_MODEL') || define('TEST_EMBEDDING_MODEL', 'test-model');

describe('EmbeddingService', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'embedding_model' => TEST_EMBEDDING_MODEL,
            'embedding_backend' => 'fake',
            'embedding_batch_size' => 100,
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
            'cache_embeddings' => true,
        ]);

        Config::set('ai.backends.fake', [
            'driver' => 'fake',
            'model' => 'test-model',
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
        ]);

        $this->fakeBackend = new FakeBackend([
            'model' => 'test-model',
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
        ]);
    });

    test('embed generates embedding via backend', function () {
        $service = new EmbeddingService($this->fakeBackend);
        $result = $service->embed('Test text');

        expect($result)->toBeArray()
            ->and($result)->toHaveCount(TEST_EMBEDDING_DIM);
    });

    test('embed uses cache on cache hit', function () {
        $cachedEmbedding = [0.1, 0.2, 0.3, 0.4];

        EmbeddingCache::factory()->create([
            'content_hash' => hash('sha256', 'Cached text::'.TEST_EMBEDDING_MODEL),
            'embedding_model' => TEST_EMBEDDING_MODEL,
            'embedding_raw' => $cachedEmbedding,
        ]);

        $service = new EmbeddingService($this->fakeBackend);
        $result = $service->embed('Cached text');

        expect($result)->toBe($cachedEmbedding);
    });

    test('embed stores to cache on cache miss', function () {
        $service = new EmbeddingService($this->fakeBackend);
        $service->embed('New text to cache');

        $cached = EmbeddingCache::where('content_hash', hash('sha256', 'New text to cache::'.TEST_EMBEDDING_MODEL))
            ->first();

        expect($cached)->not->toBeNull()
            ->and($cached->embedding_raw)->toBeArray()
            ->and($cached->embedding_raw)->toHaveCount(TEST_EMBEDDING_DIM);
    });

    test('embedBatch processes multiple texts', function () {
        $texts = ['Text one', 'Text two'];

        $service = new EmbeddingService($this->fakeBackend);
        $results = $service->embedBatch($texts);

        expect($results)->toHaveCount(2)
            ->and($results[0])->toHaveCount(TEST_EMBEDDING_DIM)
            ->and($results[1])->toHaveCount(TEST_EMBEDDING_DIM)
            ->and($results[0])->not->toBe($results[1]);
    });

    test('generateSparseEmbedding creates term frequencies', function () {
        $service = new EmbeddingService($this->fakeBackend);

        $sparse = $service->generateSparseEmbedding('Laravel is great. Laravel is awesome.');

        expect($sparse)->toBeArray()
            ->and($sparse)->toHaveKey('laravel')
            ->and($sparse['laravel'])->toBe(1.0);
    });

    test('generateSparseEmbedding removes stop words', function () {
        $service = new EmbeddingService($this->fakeBackend);

        $sparse = $service->generateSparseEmbedding('The quick fox');

        expect($sparse)->not->toHaveKey('the')
            ->and($sparse)->toHaveKey('quick');
    });

    test('getEmbeddingDimensions returns backend dimensions', function () {
        $service = new EmbeddingService($this->fakeBackend);
        $dimensions = $service->getEmbeddingDimensions();

        expect($dimensions)->toBe(TEST_EMBEDDING_DIM);
    });
});

describe('DocumentEmbeddingWriter', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'embedding_model' => TEST_EMBEDDING_MODEL,
            'embedding_backend' => 'fake',
            'embedding_batch_size' => 100,
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
            'cache_embeddings' => false,
        ]);

        $fakeBackend = new FakeBackend([
            'model' => 'test-model',
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
        ]);

        $this->writer = new DocumentEmbeddingWriter(new EmbeddingService($fakeBackend));
    });

    test('write updates DocumentChunk embedding columns', function () {
        $document = Document::factory()->create();
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->needsEmbedding()
            ->create();

        $this->writer->write(collect([$chunk]));

        $chunk->refresh();

        expect($chunk->embedding_raw)->toBeArray()
            ->and($chunk->embedding_raw)->toHaveCount(TEST_EMBEDDING_DIM)
            ->and($chunk->embedding_model)->toBe(TEST_EMBEDDING_MODEL)
            ->and($chunk->embedding_generated_at)->not->toBeNull()
            ->and($chunk->sparse_vector)->toBeArray()
            ->and($chunk->sparse_vector)->not->toBeEmpty();
    });

    test('write skips empty collection', function () {
        $this->writer->write(collect());

        expect(true)->toBeTrue();
    });

    test('writeForDocument embeds only unembedded chunks', function () {
        $document = Document::factory()->create();
        DocumentChunk::factory()->for($document)->withEmbedding()->create();
        $needs = DocumentChunk::factory()->for($document)->needsEmbedding()->create();

        $this->writer->writeForDocument($document->id);

        $needs->refresh();
        expect($needs->embedding_generated_at)->not->toBeNull();
    });
});
