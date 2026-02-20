<?php

namespace App\Jobs;

use App\Models\Embedding;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateEmbeddingJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public Embedding $embedding,
    ) {}

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->embedding->id;
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'embedding',
            'embedding:'.$this->embedding->id,
            'user:'.$this->embedding->user_id,
        ];
    }

    public function handle(EmbeddingService $embeddingService): void
    {
        if (! config('ai.rag.enabled', false)) {
            Log::info('Skipping embedding generation - RAG is disabled', [
                'embedding_id' => $this->embedding->id,
            ]);
            $this->embedding->markAsFailed('RAG is disabled');

            return;
        }

        $embedding = $this->embedding;
        $startTime = microtime(true);

        Log::info('Starting embedding generation', [
            'embedding_id' => $embedding->id,
            'model' => $embedding->model,
        ]);

        $embedding->markAsProcessing();

        try {
            $vector = $embeddingService->embed($embedding->text, $embedding->model);
            $dimensions = count($vector);
            $vectorString = $embeddingService->formatVectorForPgvector($vector);

            $embedding->markAsCompleted($vector, $dimensions);

            // Update pgvector native column
            DB::statement(
                'UPDATE embeddings SET embedding = ?::vector WHERE id = ?',
                [$vectorString, $embedding->id]
            );

            $duration = microtime(true) - $startTime;
            Log::info('Embedding generation completed', [
                'embedding_id' => $embedding->id,
                'dimensions' => $dimensions,
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (Throwable $e) {
            Log::error('Embedding generation failed', [
                'embedding_id' => $embedding->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('GenerateEmbeddingJob failed permanently', [
            'embedding_id' => $this->embedding->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->embedding->markAsFailed($exception?->getMessage() ?? 'Unknown error');
    }
}
