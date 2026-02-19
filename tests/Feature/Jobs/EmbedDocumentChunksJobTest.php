<?php

use App\Jobs\EmbedDocumentChunksJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Embedding\Writers\DocumentEmbeddingWriter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

// Use small test embeddings for speed (4 dimensions instead of 1536)
defined('TEST_EMBEDDING_DIM') || define('TEST_EMBEDDING_DIM', 4);

describe('EmbedDocumentChunksJob', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'embedding_model' => 'test-model',
            'embedding_backend' => 'fake',
            'embedding_batch_size' => 100,
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
            'cache_embeddings' => false,
        ]);

        Config::set('ai.default', 'fake');
        Config::set('ai.backends.fake', [
            'driver' => 'fake',
            'model' => 'test-model',
            'embedding_dimensions' => TEST_EMBEDDING_DIM,
        ]);
    });

    test('job delegates to DocumentEmbeddingWriter::writeForDocument', function () {
        $document = Document::factory()->create();
        DocumentChunk::factory()
            ->count(3)
            ->for($document)
            ->needsEmbedding()
            ->create();

        $mockWriter = Mockery::mock(DocumentEmbeddingWriter::class);
        $mockWriter->shouldReceive('writeForDocument')
            ->once()
            ->with($document->id, null);

        $job = new EmbedDocumentChunksJob($document);
        $job->handle($mockWriter);
    });

    test('job passes model override to writer', function () {
        $document = Document::factory()->create();

        $mockWriter = Mockery::mock(DocumentEmbeddingWriter::class);
        $mockWriter->shouldReceive('writeForDocument')
            ->once()
            ->with($document->id, 'custom-model');

        $job = new EmbedDocumentChunksJob($document, 'custom-model');
        $job->handle($mockWriter);
    });

    test('job updates document metadata after embedding', function () {
        $document = Document::factory()->create();
        DocumentChunk::factory()
            ->for($document)
            ->withEmbedding()
            ->create();

        $mockWriter = Mockery::mock(DocumentEmbeddingWriter::class);
        $mockWriter->shouldReceive('writeForDocument')->once();

        $job = new EmbedDocumentChunksJob($document);
        $job->handle($mockWriter);

        $document->refresh();
        expect($document->metadata)->toHaveKey('embedding_model')
            ->and($document->metadata)->toHaveKey('embedded_at');
    });

    test('job skips when RAG is disabled', function () {
        Config::set('ai.rag.enabled', false);

        $document = Document::factory()->create();

        $mockWriter = Mockery::mock(DocumentEmbeddingWriter::class);
        $mockWriter->shouldNotReceive('writeForDocument');

        $job = new EmbedDocumentChunksJob($document);
        $job->handle($mockWriter);
    });

    test('job tags include document ID', function () {
        $document = Document::factory()->create();

        $job = new EmbedDocumentChunksJob($document);
        $tags = $job->tags();

        expect($tags)->toContain('document:'.$document->id)
            ->and($tags)->toContain('embedding');
    });

    test('job has correct retry settings', function () {
        $document = Document::factory()->create();
        $job = new EmbedDocumentChunksJob($document);

        expect($job->tries)->toBe(3)
            ->and($job->backoff())->toBe([60, 300, 900]);
    });

    test('job can be dispatched to queue', function () {
        Queue::fake();

        $document = Document::factory()->create();

        EmbedDocumentChunksJob::dispatch($document);

        Queue::assertPushed(EmbedDocumentChunksJob::class, function ($job) use ($document) {
            return $job->document->id === $document->id;
        });
    });

    test('job uniqueId returns document ID', function () {
        $document = Document::factory()->create();
        $job = new EmbedDocumentChunksJob($document);

        expect($job->uniqueId())->toBe((string) $document->id);
    });
});
