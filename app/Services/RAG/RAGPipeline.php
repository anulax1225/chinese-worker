<?php

namespace App\Services\RAG;

use App\DTOs\RetrievalResult;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\RetrievalLog;
use App\Services\Embedding\VectorSearchService;
use Illuminate\Support\Collection;

class RAGPipeline
{
    public function __construct(
        private VectorSearchService $vectorSearch,
        private RAGContextBuilder $contextBuilder,
    ) {}

    /**
     * Execute the full RAG pipeline.
     *
     * @param  string  $query  The user query
     * @param  Collection|array|Document  $documents  Documents to search
     * @param  array<string, mixed>  $options  Pipeline options
     */
    public function execute(
        string $query,
        Collection|array|Document $documents,
        array $options = []
    ): RAGPipelineResult {
        if (! config('ai.rag.enabled', false)) {
            return RAGPipelineResult::disabled();
        }

        $startTime = microtime(true);

        // Scope to the given documents then delegate all search math to VectorSearchService
        $ids = $this->extractDocumentIds($documents);
        $source = DocumentChunk::with('document')
            ->whereIn('document_id', $ids)
            ->whereNotNull('embedding_generated_at');

        $searchResult = $this->vectorSearch->search($query, $source, $options);

        // Map generic SearchResult → RetrievalResult so RAGContextBuilder is unaffected
        $retrieval = new RetrievalResult(
            chunks: $searchResult->items,
            strategy: $searchResult->strategy,
            scores: $searchResult->scores,
            executionTimeMs: $searchResult->executionTimeMs,
        );

        // Record chunk access (was in RetrievalService)
        foreach ($retrieval->chunks as $chunk) {
            $chunk->recordAccess();
        }

        // Log retrieval for analytics (was in RetrievalService)
        $this->logRetrieval($query, $retrieval, $options);

        $context = '';
        $citations = [];

        if ($retrieval->hasChunks()) {
            $context = $this->contextBuilder->build($retrieval, $query, $options);
            $citations = $this->contextBuilder->extractCitations($retrieval->chunks);
        }

        $executionTime = (microtime(true) - $startTime) * 1000;

        return new RAGPipelineResult(
            context: $context,
            retrieval: $retrieval,
            citations: $citations,
            executionTimeMs: $executionTime,
        );
    }

    /**
     * Execute RAG for a conversation.
     *
     * Automatically retrieves documents attached to the conversation.
     *
     * @param  Conversation  $conversation  The conversation
     * @param  string  $query  The user query
     * @param  array<string, mixed>  $options  Pipeline options
     */
    public function executeForConversation(
        Conversation $conversation,
        string $query,
        array $options = []
    ): RAGPipelineResult {
        $documents = $conversation->documents;

        if ($documents->isEmpty()) {
            return RAGPipelineResult::noDocuments();
        }

        $options['conversation_id'] = $conversation->id;
        $options['user_id'] = $conversation->user_id;

        return $this->execute($query, $documents, $options);
    }

    /**
     * Check if RAG is enabled and properly configured.
     */
    public static function isEnabled(): bool
    {
        return config('ai.rag.enabled', false);
    }

    /**
     * Get the context builder instance.
     */
    public function getContextBuilder(): RAGContextBuilder
    {
        return $this->contextBuilder;
    }

    /**
     * Extract document IDs from the various supported input types.
     *
     * @return array<int>
     */
    protected function extractDocumentIds(Collection|array|Document $documents): array
    {
        if ($documents instanceof Document) {
            return [$documents->id];
        }

        if ($documents instanceof Collection) {
            return $documents->pluck('id')->toArray();
        }

        return array_map(fn ($d) => $d instanceof Document ? $d->id : $d, $documents);
    }

    /**
     * Log retrieval for analytics.
     *
     * @param  array<string, mixed>  $options
     */
    protected function logRetrieval(string $query, RetrievalResult $result, array $options): void
    {
        if (! config('ai.rag.log_retrievals', true)) {
            return;
        }

        try {
            RetrievalLog::create([
                'conversation_id' => $options['conversation_id'] ?? null,
                'user_id' => $options['user_id'] ?? null,
                'query' => $query,
                'query_expansion' => $options['query_expansion'] ?? null,
                'retrieved_chunks' => $result->getChunkIds(),
                'retrieval_strategy' => $result->strategy,
                'retrieval_scores' => $result->scores,
                'execution_time_ms' => $result->executionTimeMs,
                'chunks_found' => $result->count(),
                'average_score' => $result->averageScore(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

/**
 * Result of a RAG pipeline execution.
 */
class RAGPipelineResult
{
    public readonly bool $success;

    public readonly ?string $reason;

    public readonly int $chunksRetrieved;

    public function __construct(
        public string $context,
        public ?RetrievalResult $retrieval,
        public array $citations,
        public float $executionTimeMs,
        public bool $enabled = true,
        public bool $hasDocuments = true,
    ) {
        // Compute success based on enabled and hasDocuments
        $this->success = $this->enabled && $this->hasDocuments;

        // Compute reason for failure
        if (! $this->enabled) {
            $this->reason = 'disabled';
        } elseif (! $this->hasDocuments) {
            $this->reason = 'no_documents';
        } else {
            $this->reason = null;
        }

        // Compute chunks retrieved
        $this->chunksRetrieved = $this->retrieval?->count() ?? 0;
    }

    /**
     * Check if context was successfully retrieved.
     */
    public function hasContext(): bool
    {
        return ! empty($this->context);
    }

    /**
     * Get the number of chunks used.
     */
    public function chunkCount(): int
    {
        return $this->chunksRetrieved;
    }

    /**
     * Get the retrieval strategy used.
     */
    public function strategy(): ?string
    {
        return $this->retrieval?->strategy;
    }

    /**
     * Create a result for when RAG is disabled.
     */
    public static function disabled(): self
    {
        return new self(
            context: '',
            retrieval: null,
            citations: [],
            executionTimeMs: 0,
            enabled: false,
        );
    }

    /**
     * Create a result for when no documents are available.
     */
    public static function noDocuments(): self
    {
        return new self(
            context: '',
            retrieval: null,
            citations: [],
            executionTimeMs: 0,
            hasDocuments: false,
        );
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'reason' => $this->reason,
            'context' => $this->context,
            'citations' => $this->citations,
            'chunks_retrieved' => $this->chunksRetrieved,
            'execution_time_ms' => round($this->executionTimeMs, 2),
        ];
    }
}
