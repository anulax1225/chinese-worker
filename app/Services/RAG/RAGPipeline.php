<?php

namespace App\Services\RAG;

use App\DTOs\RetrievalResult;
use App\Models\Conversation;
use App\Models\Document;
use Illuminate\Support\Collection;

class RAGPipeline
{
    public function __construct(
        private RetrievalService $retrievalService,
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
        // Check if RAG is enabled
        if (! config('ai.rag.enabled', false)) {
            return RAGPipelineResult::disabled();
        }

        $startTime = microtime(true);

        // Step 1: Retrieve relevant chunks
        $retrieval = $this->retrievalService->retrieve($query, $documents, $options);

        // Step 2: Build context from retrieved chunks
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
        // Get documents attached to conversation
        $documents = $conversation->documents;

        if ($documents->isEmpty()) {
            return RAGPipelineResult::noDocuments();
        }

        // Add conversation context for logging
        $options['conversation_id'] = $conversation->id;
        $options['user_id'] = $conversation->user_id;

        return $this->execute($query, $documents, $options);
    }

    /**
     * Check if RAG is enabled and properly configured.
     */
    public function isEnabled(): bool
    {
        return config('ai.rag.enabled', false);
    }

    /**
     * Get the retrieval service instance.
     */
    public function getRetrievalService(): RetrievalService
    {
        return $this->retrievalService;
    }

    /**
     * Get the context builder instance.
     */
    public function getContextBuilder(): RAGContextBuilder
    {
        return $this->contextBuilder;
    }
}

/**
 * Result of a RAG pipeline execution.
 */
class RAGPipelineResult
{
    public function __construct(
        public string $context,
        public ?RetrievalResult $retrieval,
        public array $citations,
        public float $executionTimeMs,
        public bool $enabled = true,
        public bool $hasDocuments = true,
    ) {}

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
        return $this->retrieval?->count() ?? 0;
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
            'enabled' => $this->enabled,
            'has_documents' => $this->hasDocuments,
            'has_context' => $this->hasContext(),
            'chunk_count' => $this->chunkCount(),
            'strategy' => $this->strategy(),
            'execution_time_ms' => round($this->executionTimeMs, 2),
            'citations' => $this->citations,
        ];
    }
}
