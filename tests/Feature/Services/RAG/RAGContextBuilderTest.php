<?php

use App\DTOs\RetrievalResult;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RAG\RAGContextBuilder;
use Illuminate\Support\Facades\Config;

describe('RAGContextBuilder', function () {
    beforeEach(function () {
        Config::set('ai.rag.max_context_tokens', 4000);
    });

    test('build creates formatted context string', function () {
        $document = Document::factory()->create(['title' => 'test.pdf']);
        $chunks = DocumentChunk::factory()
            ->count(2)
            ->for($document)
            ->sequence(
                ['content' => 'First chunk content', 'token_count' => 50, 'chunk_index' => 0],
                ['content' => 'Second chunk content', 'token_count' => 50, 'chunk_index' => 1],
            )
            ->create();

        $result = new RetrievalResult(
            chunks: $chunks,
            strategy: 'hybrid',
            scores: [$chunks[0]->id => 0.9, $chunks[1]->id => 0.8],
        );

        $builder = new RAGContextBuilder;
        $context = $builder->build($result, 'test query');

        expect($context)->toContain('## Retrieved Context')
            ->and($context)->toContain('First chunk content')
            ->and($context)->toContain('Second chunk content')
            ->and($context)->toContain('test query');
    });

    test('build includes citations', function () {
        $document = Document::factory()->create(['title' => 'document.pdf']);
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->create(['content' => 'Content', 'token_count' => 50]);

        $result = new RetrievalResult(
            chunks: collect([$chunk]),
            strategy: 'dense',
        );

        $builder = new RAGContextBuilder;
        $context = $builder->build($result, 'query');

        expect($context)->toContain('[1]')
            ->and($context)->toContain('document.pdf')
            ->and($context)->toContain('### Sources');
    });

    test('build respects max token budget', function () {
        Config::set('ai.rag.max_context_tokens', 100);

        $document = Document::factory()->create();
        $chunks = DocumentChunk::factory()
            ->count(5)
            ->for($document)
            ->create(['token_count' => 50]); // Each chunk ~50 tokens

        $result = new RetrievalResult(
            chunks: $chunks,
            strategy: 'dense',
        );

        $builder = new RAGContextBuilder;
        $context = $builder->build($result, 'query');

        // Should only include 1-2 chunks due to budget (100 tokens, ~50 per chunk + overhead)
        $chunkCount = substr_count($context, '[1]') + substr_count($context, '[2]');
        expect($chunkCount)->toBeLessThanOrEqual(2);
    });

    test('build returns empty string for no chunks', function () {
        $result = RetrievalResult::empty();

        $builder = new RAGContextBuilder;
        $context = $builder->build($result, 'query');

        expect($context)->toBe('');
    });

    test('formatChunk includes section title', function () {
        $document = Document::factory()->create();
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->create([
                'content' => 'Chunk content',
                'section_title' => 'Introduction',
                'token_count' => 50,
            ]);

        $result = new RetrievalResult(
            chunks: collect([$chunk]),
            strategy: 'dense',
        );

        $builder = new RAGContextBuilder;
        $context = $builder->build($result, 'query', ['include_metadata' => true]);

        expect($context)->toContain('Introduction');
    });

    test('extractCitations returns citation array', function () {
        $document = Document::factory()->create(['title' => 'source.pdf']);
        $chunks = DocumentChunk::factory()
            ->count(2)
            ->for($document)
            ->sequence(
                ['content' => 'First content here...', 'chunk_index' => 0],
                ['content' => 'Second content here...', 'chunk_index' => 1],
            )
            ->create();

        $builder = new RAGContextBuilder;
        $citations = $builder->extractCitations($chunks);

        expect($citations)->toHaveCount(2)
            ->and($citations[0])->toHaveKeys(['citation', 'excerpt', 'chunk_id', 'document_id'])
            ->and($citations[0]['citation'])->toContain('source.pdf')
            ->and($citations[0]['excerpt'])->toContain('First content');
    });

    test('getTotalTokens sums chunk tokens', function () {
        $document = Document::factory()->create();
        $chunks = DocumentChunk::factory()
            ->count(3)
            ->for($document)
            ->sequence(
                ['token_count' => 100],
                ['token_count' => 200],
                ['token_count' => 150],
            )
            ->create();

        $builder = new RAGContextBuilder;
        $totalTokens = $builder->getTotalTokens($chunks);

        expect($totalTokens)->toBe(450);
    });

    test('formatForPrompt creates context from chunks directly', function () {
        $document = Document::factory()->create();
        $chunks = DocumentChunk::factory()
            ->count(2)
            ->for($document)
            ->create(['token_count' => 50]);

        $builder = new RAGContextBuilder;
        $context = $builder->formatForPrompt($chunks, 'search query');

        expect($context)->toContain('## Retrieved Context')
            ->and($context)->toContain('search query');
    });

    test('build excludes metadata when option disabled', function () {
        $document = Document::factory()->create();
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->create([
                'content' => 'Content',
                'section_title' => 'Section Title',
                'token_count' => 50,
            ]);

        $result = new RetrievalResult(
            chunks: collect([$chunk]),
            strategy: 'dense',
        );

        $builder = new RAGContextBuilder;
        $context = $builder->build($result, 'query', ['include_metadata' => false]);

        expect($context)->not->toContain('**Section:**');
    });

    test('build excludes citations when option disabled', function () {
        $document = Document::factory()->create();
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->create(['token_count' => 50]);

        $result = new RetrievalResult(
            chunks: collect([$chunk]),
            strategy: 'dense',
        );

        $builder = new RAGContextBuilder;
        $context = $builder->build($result, 'query', ['include_citations' => false]);

        expect($context)->not->toContain('### Sources');
    });
});
