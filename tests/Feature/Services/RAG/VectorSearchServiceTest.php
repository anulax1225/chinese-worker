<?php

use App\DTOs\SearchResult;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\FakeBackend;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\VectorSearchService;
use Illuminate\Support\Facades\Config;

defined('TEST_EMBEDDING_DIM') || define('TEST_EMBEDDING_DIM', 4);

describe('VectorSearchService', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'search_type' => 'hybrid',
            'top_k' => 10,
            'similarity_threshold' => 0.1,
            'hybrid_alpha' => 0.7,
            'rrf_k' => 60,
            'embedding_model' => 'test-model',
            'embedding_backend' => 'fake',
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
            'cache_embeddings' => false,
            'log_retrievals' => true,
        ]);

        Config::set('ai.backends.fake', [
            'driver' => 'fake',
            'model' => 'test-model',
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
        ]);

        $fakeBackend = new FakeBackend([
            'model' => 'test-model',
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
        ]);

        $this->embeddingService = new EmbeddingService($fakeBackend);
        $this->service = new VectorSearchService($this->embeddingService);
    });

    describe('search', function () {
        test('returns SearchResult', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test query', $source);

            expect($result)->toBeInstanceOf(SearchResult::class);
        });

        test('auto-selects strategy from config', function () {
            Config::set('ai.rag.search_type', 'dense');

            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test query', $source);

            expect($result->strategy)->toBe('dense');
        });

        test('handles empty results gracefully', function () {
            $document = Document::factory()->create();
            // No chunks created

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test query', $source);

            expect($result->hasItems())->toBeFalse()
                ->and($result->count())->toBe(0);
        });

        test('filters by document IDs via builder scope', function () {
            $doc1 = Document::factory()->create();
            $doc2 = Document::factory()->create();

            DocumentChunk::factory()->for($doc1)->withEmbedding()->create();
            DocumentChunk::factory()->for($doc2)->withEmbedding()->create();

            // Scope the builder to doc1 only
            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$doc1->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test', $source);

            foreach ($result->items as $item) {
                expect($item->document_id)->toBe($doc1->id);
            }
        });

        test('respects top_k limit', function () {
            Config::set('ai.rag.top_k', 2);

            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->count(5)
                ->for($document)
                ->withEmbedding()
                ->create();

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test', $source, ['strategy' => 'dense']);

            expect($result->count())->toBeLessThanOrEqual(2);
        });
    });

    describe('denseSearch', function () {
        test('returns items with dense strategy', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->count(3)
                ->for($document)
                ->withEmbedding()
                ->create();

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test query', $source, ['strategy' => 'dense']);

            expect($result->strategy)->toBe('dense')
                ->and($result->items)->not->toBeEmpty();
        });
    });

    describe('sparseSearch', function () {
        test('returns SearchResult with sparse strategy', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id]);

            $result = $this->service->search('Laravel framework', $source, ['strategy' => 'sparse']);

            expect($result->strategy)->toBe('sparse');
        });

        test('returns chunks matching sparse_vector keywords', function () {
            $document = Document::factory()->create();
            $chunk = DocumentChunk::factory()
                ->for($document)
                ->withContent('This document discusses the penguin migration patterns in Antarctica')
                ->withEmbedding()
                ->create();

            // Manually set a sparse_vector so the GIN filter matches
            $chunk->update(['sparse_vector' => ['penguin' => 1.0, 'migration' => 0.8, 'antarctica' => 0.6]]);

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id]);

            $result = $this->service->search('penguin migration', $source, ['strategy' => 'sparse']);

            expect($result->strategy)->toBe('sparse')
                ->and($result->items->pluck('id'))->toContain($chunk->id);
        });
    });

    describe('hybridSearch', function () {
        test('combines dense and sparse results', function () {
            Config::set('ai.rag.search_type', 'hybrid');

            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->count(3)
                ->for($document)
                ->withEmbedding()
                ->create();

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test query', $source);

            expect($result->strategy)->toBe('hybrid');
        });

        test('returns SearchResult for hybrid strategy', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $source = DocumentChunk::with('document')
                ->whereIn('document_id', [$document->id])
                ->whereNotNull('embedding_generated_at');

            $result = $this->service->search('test', $source, ['strategy' => 'hybrid']);

            expect($result)->toBeInstanceOf(SearchResult::class);
        });
    });
});
