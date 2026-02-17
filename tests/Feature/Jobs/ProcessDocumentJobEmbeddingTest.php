<?php

use App\DTOs\Document\CleaningResult;
use App\DTOs\Document\ExtractionResult;
use App\DTOs\Document\StructuredContent;
use App\Jobs\EmbedDocumentChunksJob;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\Document\CleaningPipeline;
use App\Services\Document\DocumentIngestionService;
use App\Services\Document\StructurePipeline;
use App\Services\Document\TextExtractorRegistry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

describe('ProcessDocumentJob embedding dispatch', function () {
    beforeEach(function () {
        Config::set('ai.rag', [
            'enabled' => true,
            'embedding_model' => 'test-model',
            'embedding_backend' => 'fake',
            'embedding_dimensions' => 4,
            'cache_embeddings' => false,
        ]);
    });

    /**
     * Bind mocked services and run the job's handle method.
     */
    $runJob = function (Document $document): void {
        $extractedText = 'Test content for chunking with enough words to form a chunk';

        $mockExtractor = Mockery::mock(TextExtractorRegistry::class);
        $mockExtractor->shouldReceive('extract')
            ->once()
            ->andReturn(ExtractionResult::success($extractedText));

        $mockIngestion = Mockery::mock(DocumentIngestionService::class);
        $mockIngestion->shouldReceive('getSourcePath')
            ->once()
            ->andReturn('/tmp/test.txt');

        $mockCleaning = Mockery::mock(CleaningPipeline::class);
        $mockCleaning->shouldReceive('clean')
            ->once()
            ->andReturn(new CleaningResult(
                text: 'Test content for chunking',
                stepsApplied: ['test'],
                charactersBefore: 100,
                charactersAfter: 80,
            ));

        $mockStructure = Mockery::mock(StructurePipeline::class);
        $mockStructure->shouldReceive('process')
            ->once()
            ->andReturn(new StructuredContent(
                text: 'Test content for chunking',
                sections: [],
                metadata: [],
            ));

        app()->instance(TextExtractorRegistry::class, $mockExtractor);
        app()->instance(DocumentIngestionService::class, $mockIngestion);
        app()->instance(CleaningPipeline::class, $mockCleaning);
        app()->instance(StructurePipeline::class, $mockStructure);

        $job = new ProcessDocumentJob($document);
        $job->handle(
            app(TextExtractorRegistry::class),
            app(DocumentIngestionService::class),
            app(CleaningPipeline::class),
            app(StructurePipeline::class),
        );
    };

    test('dispatches EmbedDocumentChunksJob when RAG is enabled', function () use ($runJob) {
        Bus::fake();

        Config::set('ai.rag.enabled', true);

        $document = Document::factory()->create();

        $runJob($document);

        Bus::assertDispatched(EmbedDocumentChunksJob::class, function ($job) use ($document) {
            return $job->document->id === $document->id;
        });
    });

    test('does not dispatch EmbedDocumentChunksJob when RAG is disabled', function () use ($runJob) {
        Bus::fake();

        Config::set('ai.rag.enabled', false);

        $document = Document::factory()->create();

        $runJob($document);

        Bus::assertNotDispatched(EmbedDocumentChunksJob::class);
    });
});
