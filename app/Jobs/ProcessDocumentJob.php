<?php

namespace App\Jobs;

use App\Enums\DocumentStageType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentStage;
use App\Services\Document\CleaningPipeline;
use App\Services\Document\DocumentIngestionService;
use App\Services\Document\StructurePipeline;
use App\Services\Document\TextExtractorRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public Document $document
    ) {}

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'document:'.$this->document->id,
            'user:'.$this->document->user_id,
        ];
    }

    public function handle(
        TextExtractorRegistry $extractorRegistry,
        DocumentIngestionService $ingestionService,
        CleaningPipeline $cleaningPipeline,
        StructurePipeline $structurePipeline
    ): void {
        $document = $this->document;
        $startTime = microtime(true);

        Log::info('Starting document processing', [
            'document_id' => $document->id,
            'mime_type' => $document->mime_type,
        ]);

        try {
            // Phase 1: Extraction
            $this->runExtractionPhase($document, $extractorRegistry, $ingestionService);

            // Phase 2: Cleaning
            $this->runCleaningPhase($document, $cleaningPipeline);

            // Phase 3: Normalization
            $this->runNormalizationPhase($document, $structurePipeline);

            // Phase 4: Chunking (simplified for now)
            $this->runChunkingPhase($document);

            // Mark as ready
            $document->markAs(DocumentStatus::Ready);

            // Dispatch embedding job if RAG is enabled
            if (config('ai.rag.enabled', true)) {
                EmbedDocumentChunksJob::dispatch($document);
            }

            $duration = microtime(true) - $startTime;
            Log::info('Document processing completed', [
                'document_id' => $document->id,
                'duration_seconds' => round($duration, 2),
            ]);

        } catch (Throwable $e) {
            $this->handleFailure($document, $e);
        }
    }

    /**
     * Run the text extraction phase.
     */
    protected function runExtractionPhase(
        Document $document,
        TextExtractorRegistry $extractorRegistry,
        DocumentIngestionService $ingestionService
    ): void {
        $document->markAs(DocumentStatus::Extracting);

        $sourcePath = $ingestionService->getSourcePath($document);
        $result = $extractorRegistry->extract($sourcePath, $document->mime_type);

        if (! $result->success) {
            throw new \RuntimeException("Extraction failed: {$result->error}");
        }

        // Store the extraction result
        $this->storeStage($document, DocumentStageType::Extracted, $result->text, [
            'word_count' => $result->wordCount(),
            'character_count' => $result->characterCount(),
            'warnings' => $result->warnings,
            'extractor_metadata' => $result->metadata,
        ]);

        // Update document metadata
        $metadata = $document->metadata ?? [];
        $metadata['word_count'] = $result->wordCount();
        $metadata['character_count'] = $result->characterCount();
        $metadata = array_merge($metadata, $result->metadata);
        $document->update(['metadata' => $metadata]);
    }

    /**
     * Run the content cleaning phase.
     */
    protected function runCleaningPhase(Document $document, CleaningPipeline $pipeline): void
    {
        $document->markAs(DocumentStatus::Cleaning);

        // Get the extracted text
        $extractedStage = $document->getLatestStage(DocumentStageType::Extracted);
        if (! $extractedStage) {
            throw new \RuntimeException('Extraction stage not found');
        }

        $text = $extractedStage->content;

        // Run through the cleaning pipeline
        $result = $pipeline->clean($text);

        $this->storeStage($document, DocumentStageType::Cleaned, $result->text, [
            'characters_before' => $result->charactersBefore,
            'characters_after' => $result->charactersAfter,
            'characters_removed' => $result->charactersRemoved(),
            'reduction_percentage' => $result->reductionPercentage(),
            'steps_applied' => $result->stepsApplied,
        ]);
    }

    /**
     * Run the structure normalization phase.
     */
    protected function runNormalizationPhase(Document $document, StructurePipeline $pipeline): void
    {
        $document->markAs(DocumentStatus::Normalizing);

        // Get the cleaned text
        $cleanedStage = $document->getLatestStage(DocumentStageType::Cleaned);
        if (! $cleanedStage) {
            throw new \RuntimeException('Cleaning stage not found');
        }

        $text = $cleanedStage->content;

        // Run through the structure pipeline
        $result = $pipeline->process($text);

        $this->storeStage($document, DocumentStageType::Normalized, $result->text, [
            'sections_detected' => $result->sectionCount(),
            'section_titles' => $result->getSectionTitles(),
            'processors_applied' => $result->metadata['processors_applied'] ?? [],
            'metadata' => $result->metadata,
        ]);
    }

    /**
     * Run the chunking phase.
     * This is a simplified version - full chunking will be added in Sprint 3.
     */
    protected function runChunkingPhase(Document $document): void
    {
        $document->markAs(DocumentStatus::Chunking);

        // Get the normalized text
        $normalizedStage = $document->getLatestStage(DocumentStageType::Normalized);
        if (! $normalizedStage) {
            throw new \RuntimeException('Normalization stage not found');
        }

        $text = $normalizedStage->content;

        // Simple chunking for now (full chunking service in Sprint 3)
        $chunks = $this->simpleChunk($text);

        foreach ($chunks as $index => $chunk) {
            $document->chunks()->create([
                'chunk_index' => $index,
                'content' => $chunk['content'],
                'token_count' => $chunk['token_count'],
                'start_offset' => $chunk['start_offset'],
                'end_offset' => $chunk['end_offset'],
                'section_title' => null,
                'metadata' => [],
                'created_at' => now(),
            ]);
        }

        // Store a summary in the stage
        $this->storeStage($document, DocumentStageType::Chunked, '', [
            'chunk_count' => count($chunks),
            'total_tokens' => array_sum(array_column($chunks, 'token_count')),
        ]);
    }

    /**
     * Simple chunking by paragraphs (placeholder for full chunking service).
     *
     * @return array<array{content: string, token_count: int, start_offset: int, end_offset: int}>
     */
    protected function simpleChunk(string $text): array
    {
        $maxTokens = config('document.chunking.default_max_tokens', 1000);
        $chunks = [];
        $offset = 0;

        // Split by double newlines (paragraphs)
        $paragraphs = preg_split('/\n\n+/', $text);

        $currentChunk = '';
        $currentOffset = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                $offset += 2; // Account for the newlines

                continue;
            }

            $paragraphTokens = $this->estimateTokens($paragraph);
            $currentTokens = $this->estimateTokens($currentChunk);

            if ($currentTokens + $paragraphTokens > $maxTokens && ! empty($currentChunk)) {
                // Save current chunk
                $chunks[] = [
                    'content' => trim($currentChunk),
                    'token_count' => $currentTokens,
                    'start_offset' => $currentOffset,
                    'end_offset' => $offset,
                ];

                $currentChunk = $paragraph;
                $currentOffset = $offset;
            } else {
                $currentChunk .= ($currentChunk ? "\n\n" : '').$paragraph;
            }

            $offset += strlen($paragraph) + 2;
        }

        // Don't forget the last chunk
        if (! empty($currentChunk)) {
            $chunks[] = [
                'content' => trim($currentChunk),
                'token_count' => $this->estimateTokens($currentChunk),
                'start_offset' => $currentOffset,
                'end_offset' => $offset,
            ];
        }

        return $chunks;
    }

    /**
     * Simple token estimation (characters / 4).
     */
    protected function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Store a processing stage.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function storeStage(
        Document $document,
        DocumentStageType $stage,
        string $content,
        array $metadata = []
    ): void {
        DocumentStage::create([
            'document_id' => $document->id,
            'stage' => $stage,
            'content' => $content,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Handle job failure.
     */
    protected function handleFailure(Document $document, Throwable $e): void
    {
        Log::error('Document processing failed', [
            'document_id' => $document->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $document->fail($e->getMessage());
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->document->fail($exception->getMessage());
        }
    }
}
