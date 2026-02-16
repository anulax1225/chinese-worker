<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RAG\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmbedDocumentChunksJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public Document $document,
        public ?string $model = null
    ) {}

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'embedding',
            'document:'.$this->document->id,
            'user:'.$this->document->user_id,
        ];
    }

    public function handle(EmbeddingService $embeddingService): void
    {
        $document = $this->document;
        $startTime = microtime(true);

        Log::info('Starting document embedding', [
            'document_id' => $document->id,
            'model' => $this->model ?? config('ai.rag.embedding_model'),
        ]);

        try {
            // Get chunks that need embedding
            $chunks = DocumentChunk::where('document_id', $document->id)
                ->whereNull('embedding_generated_at')
                ->get();

            if ($chunks->isEmpty()) {
                Log::info('No chunks need embedding', [
                    'document_id' => $document->id,
                ]);

                return;
            }

            // Process in batches to avoid memory issues
            $batchSize = config('ai.rag.embedding_batch_size', 100);
            $chunked = $chunks->chunk($batchSize);

            $totalEmbedded = 0;

            foreach ($chunked as $batch) {
                $embeddingService->embedChunks($batch, $this->model);
                $totalEmbedded += $batch->count();

                Log::debug('Embedded batch of chunks', [
                    'document_id' => $document->id,
                    'batch_size' => $batch->count(),
                    'total_embedded' => $totalEmbedded,
                    'remaining' => $chunks->count() - $totalEmbedded,
                ]);
            }

            // Update document metadata
            $metadata = $document->metadata ?? [];
            $metadata['embedding_model'] = $this->model ?? config('ai.rag.embedding_model');
            $metadata['embedded_at'] = now()->toISOString();
            $metadata['embedded_chunks'] = $totalEmbedded;
            $document->update(['metadata' => $metadata]);

            $duration = microtime(true) - $startTime;
            Log::info('Document embedding completed', [
                'document_id' => $document->id,
                'chunks_embedded' => $totalEmbedded,
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (Throwable $e) {
            Log::error('Document embedding failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('EmbedDocumentChunksJob failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception?->getMessage(),
        ]);

        // Update document metadata to indicate embedding failure
        $metadata = $this->document->metadata ?? [];
        $metadata['embedding_failed'] = true;
        $metadata['embedding_error'] = $exception?->getMessage();
        $this->document->update(['metadata' => $metadata]);
    }
}
