<?php

use App\DTOs\RetrievalResult;
use App\DTOs\SearchResult;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Embedding\VectorSearchService;
use App\Services\RAG\RAGContextBuilder;
use App\Services\RAG\RAGPipeline;
use App\Services\RAG\RAGPipelineResult;
use Illuminate\Support\Facades\Config;

describe('RAGPipeline', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'search_type' => 'hybrid',
            'top_k' => 10,
            'similarity_threshold' => 0.7,
            'max_context_tokens' => 4000,
            'embedding_model' => 'test-model',
            'embedding_backend' => 'fake',
            'embedding_dimensions' => 4,
            'cache_embeddings' => false,
        ]);

        Config::set('ai.default', 'fake');
        Config::set('ai.backends.fake', [
            'driver' => 'fake',
            'model' => 'test-model',
            'embedding_dimensions' => 4,
        ]);
    });

    test('execute returns RAGPipelineResult', function () {
        $document = Document::factory()->create();
        $chunks = DocumentChunk::factory()
            ->count(2)
            ->for($document)
            ->withEmbedding()
            ->create();

        $mockSearchResult = new SearchResult(
            items: $chunks,
            strategy: 'hybrid',
            scores: [$chunks[0]->id => 0.9, $chunks[1]->id => 0.8],
            executionTimeMs: 50.0,
        );

        $mockVectorSearch = Mockery::mock(VectorSearchService::class);
        $mockVectorSearch->shouldReceive('search')
            ->once()
            ->andReturn($mockSearchResult);

        $mockContextBuilder = Mockery::mock(RAGContextBuilder::class);
        $mockContextBuilder->shouldReceive('build')
            ->once()
            ->andReturn('## Retrieved Context\nContext here...');
        $mockContextBuilder->shouldReceive('extractCitations')
            ->once()
            ->andReturn([]);

        $pipeline = new RAGPipeline($mockVectorSearch, $mockContextBuilder);
        $result = $pipeline->execute('test query', collect([$document]));

        expect($result)->toBeInstanceOf(RAGPipelineResult::class)
            ->and($result->success)->toBeTrue()
            ->and($result->context)->toContain('Retrieved Context');
    });

    test('execute returns disabled result when RAG disabled', function () {
        Config::set('ai.rag.enabled', false);

        $mockVectorSearch = Mockery::mock(VectorSearchService::class);
        $mockVectorSearch->shouldNotReceive('search');

        $mockContextBuilder = Mockery::mock(RAGContextBuilder::class);

        $pipeline = new RAGPipeline($mockVectorSearch, $mockContextBuilder);
        $result = $pipeline->execute('test query', collect());

        expect($result->success)->toBeFalse()
            ->and($result->reason)->toBe('disabled')
            ->and($result->context)->toBe('');
    });

    test('executeForConversation uses conversation documents', function () {
        $conversation = Conversation::factory()->create();
        $document = Document::factory()->create();

        // Attach document to conversation
        $conversation->documents()->attach($document->id, ['attached_at' => now()]);

        $chunks = DocumentChunk::factory()
            ->for($document)
            ->withEmbedding()
            ->create();

        $mockSearchResult = new SearchResult(
            items: collect([$chunks]),
            strategy: 'hybrid',
            scores: [],
        );

        $mockVectorSearch = Mockery::mock(VectorSearchService::class);
        $mockVectorSearch->shouldReceive('search')
            ->once()
            ->andReturn($mockSearchResult);

        $mockContextBuilder = Mockery::mock(RAGContextBuilder::class);
        $mockContextBuilder->shouldReceive('build')->andReturn('Context');
        $mockContextBuilder->shouldReceive('extractCitations')->andReturn([]);

        $pipeline = new RAGPipeline($mockVectorSearch, $mockContextBuilder);
        $result = $pipeline->executeForConversation($conversation, 'query');

        expect($result->success)->toBeTrue();
    });

    test('executeForConversation returns noDocuments when empty', function () {
        $conversation = Conversation::factory()->create();
        // No documents attached

        $mockVectorSearch = Mockery::mock(VectorSearchService::class);
        $mockVectorSearch->shouldNotReceive('search');

        $mockContextBuilder = Mockery::mock(RAGContextBuilder::class);

        $pipeline = new RAGPipeline($mockVectorSearch, $mockContextBuilder);
        $result = $pipeline->executeForConversation($conversation, 'query');

        expect($result->success)->toBeFalse()
            ->and($result->reason)->toBe('no_documents');
    });

    test('isEnabled reads config correctly', function () {
        Config::set('ai.rag.enabled', true);
        expect(RAGPipeline::isEnabled())->toBeTrue();

        Config::set('ai.rag.enabled', false);
        expect(RAGPipeline::isEnabled())->toBeFalse();
    });

    test('pipeline measures execution time', function () {
        $document = Document::factory()->create();
        $chunks = DocumentChunk::factory()
            ->for($document)
            ->withEmbedding()
            ->create();

        $mockSearchResult = new SearchResult(
            items: collect([$chunks]),
            strategy: 'hybrid',
            scores: [],
            executionTimeMs: 100.0,
        );

        $mockVectorSearch = Mockery::mock(VectorSearchService::class);
        $mockVectorSearch->shouldReceive('search')->andReturn($mockSearchResult);

        $mockContextBuilder = Mockery::mock(RAGContextBuilder::class);
        $mockContextBuilder->shouldReceive('build')->andReturn('Context');
        $mockContextBuilder->shouldReceive('extractCitations')->andReturn([]);

        $pipeline = new RAGPipeline($mockVectorSearch, $mockContextBuilder);
        $result = $pipeline->execute('query', collect([$document]));

        expect($result->executionTimeMs)->toBeGreaterThan(0);
    });

    test('pipeline includes citations in result', function () {
        $document = Document::factory()->create(['title' => 'source.pdf']);
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->withEmbedding()
            ->create();

        $mockSearchResult = new SearchResult(
            items: collect([$chunk]),
            strategy: 'hybrid',
            scores: [],
        );

        $mockCitations = [
            ['citation' => '[1] source.pdf', 'chunk_id' => $chunk->id],
        ];

        $mockVectorSearch = Mockery::mock(VectorSearchService::class);
        $mockVectorSearch->shouldReceive('search')->andReturn($mockSearchResult);

        $mockContextBuilder = Mockery::mock(RAGContextBuilder::class);
        $mockContextBuilder->shouldReceive('build')->andReturn('Context with [1]');
        $mockContextBuilder->shouldReceive('extractCitations')->andReturn($mockCitations);

        $pipeline = new RAGPipeline($mockVectorSearch, $mockContextBuilder);
        $result = $pipeline->execute('query', collect([$document]));

        expect($result->citations)->toBe($mockCitations)
            ->and($result->citations)->toHaveCount(1);
    });

    test('pipeline returns empty context when no chunks found', function () {
        $document = Document::factory()->create();
        // No chunks

        $mockSearchResult = SearchResult::empty();

        $mockVectorSearch = Mockery::mock(VectorSearchService::class);
        $mockVectorSearch->shouldReceive('search')->andReturn($mockSearchResult);

        $mockContextBuilder = Mockery::mock(RAGContextBuilder::class);

        $pipeline = new RAGPipeline($mockVectorSearch, $mockContextBuilder);
        $result = $pipeline->execute('query', collect([$document]));

        expect($result->success)->toBeTrue()
            ->and($result->context)->toBe('')
            ->and($result->chunksRetrieved)->toBe(0);
    });

    test('result toArray returns correct structure', function () {
        $chunks = collect([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
            (object) ['id' => 4],
            (object) ['id' => 5],
        ]);

        $retrieval = new RetrievalResult(
            chunks: $chunks,
            strategy: 'hybrid',
        );

        $result = new RAGPipelineResult(
            context: 'Context here',
            retrieval: $retrieval,
            citations: [['citation' => '[1]']],
            executionTimeMs: 150.5,
        );

        $array = $result->toArray();

        expect($array)->toHaveKeys(['success', 'context', 'citations', 'chunks_retrieved', 'execution_time_ms'])
            ->and($array['success'])->toBeTrue()
            ->and($array['chunks_retrieved'])->toBe(5)
            ->and($array['execution_time_ms'])->toBe(150.5);
    });
});
