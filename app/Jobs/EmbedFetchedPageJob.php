<?php

namespace App\Jobs;

use App\Models\FetchedPage;
use App\Services\Embedding\Writers\WebFetchEmbeddingWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmbedFetchedPageJob implements ShouldQueue
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
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public FetchedPage $page,
        public ?string $model = null,
    ) {}

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'fetched-page-'.$this->page->id;
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
            'fetched_page:'.$this->page->id,
        ];
    }

    public function handle(WebFetchEmbeddingWriter $writer): void
    {
        $startTime = microtime(true);

        Log::info('Starting fetched page embedding', [
            'fetched_page_id' => $this->page->id,
            'url' => $this->page->url,
            'model' => $this->model ?? config('ai.rag.embedding_model'),
        ]);

        try {
            $chunks = $this->page->chunks()->needsEmbedding()->get();

            if ($chunks->isEmpty()) {
                Log::info('No chunks need embedding for fetched page', [
                    'fetched_page_id' => $this->page->id,
                ]);

                return;
            }

            $writer->write($chunks, $this->model);

            $this->page->update(['embedded_at' => now()]);

            $duration = microtime(true) - $startTime;
            Log::info('Fetched page embedding completed', [
                'fetched_page_id' => $this->page->id,
                'chunks_embedded' => $chunks->count(),
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (Throwable $e) {
            Log::error('Fetched page embedding failed', [
                'fetched_page_id' => $this->page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('EmbedFetchedPageJob failed permanently', [
            'fetched_page_id' => $this->page->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
