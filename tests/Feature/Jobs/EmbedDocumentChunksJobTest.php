<?php

use App\Jobs\EmbedDocumentChunksJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RAG\EmbeddingService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

describe('EmbedDocumentChunksJob', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_batch_size' => 100,
        ]);

        $this->mockEmbedding = array_fill(0, 1536, 0.1);
    });

    test('job processes all document chunks', function () {
        $document = Document::factory()->create();
        $chunks = DocumentChunk::factory()
            ->count(3)
            ->for($document)
            ->needsEmbedding()
            ->create();

        $mockService = Mockery::mock(EmbeddingService::class);
        $mockService->shouldReceive('embedChunks')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->count() === 3));

        app()->instance(EmbeddingService::class, $mockService);

        $job = new EmbedDocumentChunksJob($document);
        $job->handle(app(EmbeddingService::class));

        expect(true)->toBeTrue(); // Job completed without exception
    });

    test('job updates embedding_generated_at timestamp', function () {
        $document = Document::factory()->create();
        $chunk = DocumentChunk::factory()
            ->for($document)
            ->needsEmbedding()
            ->create();

        expect($chunk->embedding_generated_at)->toBeNull();

        $mockService = Mockery::mock(EmbeddingService::class);
        $mockService->shouldReceive('embedChunks')
            ->once()
            ->andReturnUsing(function ($chunks) {
                foreach ($chunks as $chunk) {
                    $chunk->update([
                        'embedding_generated_at' => now(),
                        'embedding_model' => 'text-embedding-3-small',
                    ]);
                }
            });

        app()->instance(EmbeddingService::class, $mockService);

        $job = new EmbedDocumentChunksJob($document);
        $job->handle(app(EmbeddingService::class));

        $chunk->refresh();
        expect($chunk->embedding_generated_at)->not->toBeNull();
    });

    test('job skips already embedded chunks', function () {
        $document = Document::factory()->create();

        // Create already embedded chunk
        DocumentChunk::factory()
            ->for($document)
            ->withEmbedding()
            ->create();

        // Create chunk needing embedding
        $needsEmbedding = DocumentChunk::factory()
            ->for($document)
            ->needsEmbedding()
            ->create();

        $mockService = Mockery::mock(EmbeddingService::class);
        $mockService->shouldReceive('embedChunks')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->count() === 1 && $arg->first()->id === $needsEmbedding->id));

        app()->instance(EmbeddingService::class, $mockService);

        $job = new EmbedDocumentChunksJob($document);
        $job->handle(app(EmbeddingService::class));
    });

    test('job tags include document ID', function () {
        $document = Document::factory()->create();

        $job = new EmbedDocumentChunksJob($document);
        $tags = $job->tags();

        expect($tags)->toContain('document:'.$document->id)
            ->and($tags)->toContain('embed-chunks');
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

    test('job skips when no chunks need embedding', function () {
        $document = Document::factory()->create();
        // All chunks already have embeddings
        DocumentChunk::factory()
            ->count(2)
            ->for($document)
            ->withEmbedding()
            ->create();

        $mockService = Mockery::mock(EmbeddingService::class);
        $mockService->shouldNotReceive('embedChunks');

        app()->instance(EmbeddingService::class, $mockService);

        $job = new EmbedDocumentChunksJob($document);
        $job->handle(app(EmbeddingService::class));

        expect(true)->toBeTrue(); // Completed without calling service
    });

    test('job skips when RAG is disabled', function () {
        Config::set('ai.rag.enabled', false);

        $document = Document::factory()->create();
        DocumentChunk::factory()
            ->for($document)
            ->needsEmbedding()
            ->create();

        $mockService = Mockery::mock(EmbeddingService::class);
        $mockService->shouldNotReceive('embedChunks');

        app()->instance(EmbeddingService::class, $mockService);

        $job = new EmbedDocumentChunksJob($document);
        $job->handle(app(EmbeddingService::class));
    });

    test('job respects batch size config', function () {
        Config::set('ai.rag.embedding_batch_size', 2);

        $document = Document::factory()->create();
        DocumentChunk::factory()
            ->count(5)
            ->for($document)
            ->needsEmbedding()
            ->create();

        $callCount = 0;
        $mockService = Mockery::mock(EmbeddingService::class);
        $mockService->shouldReceive('embedChunks')
            ->times(3) // 5 chunks / 2 batch = 3 calls
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
            });

        app()->instance(EmbeddingService::class, $mockService);

        $job = new EmbedDocumentChunksJob($document);
        $job->handle(app(EmbeddingService::class));

        expect($callCount)->toBe(3);
    });

    test('job uniqueId returns document ID', function () {
        $document = Document::factory()->create();
        $job = new EmbedDocumentChunksJob($document);

        expect($job->uniqueId())->toBe((string) $document->id);
    });
});
