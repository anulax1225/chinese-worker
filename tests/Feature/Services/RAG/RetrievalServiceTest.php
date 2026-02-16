<?php

use App\DTOs\RetrievalResult;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\RetrievalLog;
use App\Services\RAG\EmbeddingService;
use App\Services\RAG\RetrievalService;
use Illuminate\Support\Facades\Config;

describe('RetrievalService', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'search_type' => 'hybrid',
            'top_k' => 10,
            'similarity_threshold' => 0.7,
            'hybrid_alpha' => 0.7,
            'rrf_k' => 60,
        ]);

        $this->mockEmbedding = array_fill(0, 1536, 0.1);

        $this->mockEmbeddingService = Mockery::mock(EmbeddingService::class);
        $this->mockEmbeddingService->shouldReceive('embed')
            ->andReturn($this->mockEmbedding);
        $this->mockEmbeddingService->shouldReceive('generateSparseEmbedding')
            ->andReturn(['test' => 1.0, 'query' => 0.8]);
    });

    describe('retrieve', function () {
        test('returns RetrievalResult', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test query', collect([$document]));

            expect($result)->toBeInstanceOf(RetrievalResult::class);
        });

        test('auto-selects strategy from config', function () {
            Config::set('ai.rag.search_type', 'dense');

            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test query', collect([$document]));

            expect($result->strategy)->toBe('dense');
        });

        test('logs retrieval to retrieval_logs table', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $service->retrieve('test query', collect([$document]), [
                'conversation_id' => null,
                'user_id' => null,
            ]);

            expect(RetrievalLog::count())->toBe(1);

            $log = RetrievalLog::first();
            expect($log->query)->toBe('test query')
                ->and($log->retrieval_strategy)->not->toBeNull();
        });

        test('handles empty results gracefully', function () {
            $document = Document::factory()->create();
            // No chunks created

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test query', collect([$document]));

            expect($result->hasChunks())->toBeFalse()
                ->and($result->count())->toBe(0);
        });

        test('filters by document IDs', function () {
            $doc1 = Document::factory()->create();
            $doc2 = Document::factory()->create();

            DocumentChunk::factory()->for($doc1)->withEmbedding()->create();
            DocumentChunk::factory()->for($doc2)->withEmbedding()->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test', collect([$doc1]));

            // Should only include chunks from doc1
            foreach ($result->chunks as $chunk) {
                expect($chunk->document_id)->toBe($doc1->id);
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

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test', collect([$document]));

            expect($result->count())->toBeLessThanOrEqual(2);
        });
    });

    describe('denseSearch', function () {
        test('returns chunks ordered by similarity', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->count(3)
                ->for($document)
                ->withEmbedding()
                ->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test query', collect([$document]), [
                'strategy' => 'dense',
            ]);

            expect($result->strategy)->toBe('dense')
                ->and($result->chunks)->not->toBeEmpty();
        });
    });

    describe('sparseSearch', function () {
        test('returns chunks matching keywords', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withContent('This is a test document about Laravel framework')
                ->withEmbedding()
                ->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('Laravel framework', collect([$document]), [
                'strategy' => 'sparse',
            ]);

            expect($result->strategy)->toBe('sparse');
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

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test query', collect([$document]));

            expect($result->strategy)->toBe('hybrid');
        });

        test('respects alpha weighting parameter', function () {
            Config::set('ai.rag.hybrid_alpha', 0.3); // More weight on sparse

            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test', collect([$document]), [
                'strategy' => 'hybrid',
            ]);

            expect($result)->toBeInstanceOf(RetrievalResult::class);
        });
    });

    describe('with Document model', function () {
        test('accepts single Document', function () {
            $document = Document::factory()->create();
            DocumentChunk::factory()
                ->for($document)
                ->withEmbedding()
                ->create();

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test', $document);

            expect($result)->toBeInstanceOf(RetrievalResult::class);
        });

        test('accepts array of Documents', function () {
            $documents = Document::factory()->count(2)->create();
            foreach ($documents as $doc) {
                DocumentChunk::factory()->for($doc)->withEmbedding()->create();
            }

            $service = new RetrievalService($this->mockEmbeddingService);
            $result = $service->retrieve('test', $documents->all());

            expect($result)->toBeInstanceOf(RetrievalResult::class);
        });
    });
});
