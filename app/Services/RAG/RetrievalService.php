<?php

namespace App\Services\RAG;

use App\DTOs\RetrievalResult;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\RetrievalLog;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService,
    ) {}

    /**
     * Main retrieve method - routes to appropriate strategy.
     *
     * @param  string  $query  The search query
     * @param  Collection|array|Document  $documents  Documents to search within
     * @param  array<string, mixed>  $options  Search options
     */
    public function retrieve(
        string $query,
        Collection|array|Document $documents,
        array $options = []
    ): RetrievalResult {
        $strategy = $options['strategy'] ?? config('ai.rag.search_type', 'hybrid');
        $startTime = microtime(true);

        $result = match ($strategy) {
            'dense' => $this->denseSearch($query, $documents, $options),
            'sparse' => $this->sparseSearch($query, $documents, $options),
            'hybrid' => $this->hybridSearch($query, $documents, $options),
            default => throw new InvalidArgumentException("Unknown retrieval strategy: {$strategy}"),
        };

        $executionTime = (microtime(true) - $startTime) * 1000;

        // Record access for analytics
        foreach ($result->chunks as $chunk) {
            $chunk->recordAccess();
        }

        $finalResult = new RetrievalResult(
            chunks: $result->chunks,
            strategy: $result->strategy,
            scores: $result->scores,
            executionTimeMs: $executionTime,
        );

        // Log retrieval for analytics
        $this->logRetrieval($query, $finalResult, $options);

        return $finalResult;
    }

    /**
     * Dense vector search (semantic similarity).
     *
     * Uses pgvector to find semantically similar chunks.
     *
     * @param  string  $query  The search query
     * @param  Collection|array|Document  $documents  Documents to search within
     * @param  array<string, mixed>  $options  Search options
     */
    public function denseSearch(
        string $query,
        Collection|array|Document $documents,
        array $options = []
    ): RetrievalResult {
        $model = $options['embedding_model'] ?? config('ai.rag.embedding_model');
        $topK = $options['top_k'] ?? config('ai.rag.top_k', 10);
        $threshold = $options['threshold'] ?? config('ai.rag.similarity_threshold', 0.3);

        // Embed query
        $queryEmbedding = $this->embeddingService->embed($query, $model);
        $embeddingString = $this->formatVectorForPgvector($queryEmbedding);

        // Build base query
        $baseQuery = $this->buildBaseQuery($documents);

        // Perform semantic search using pgvector
        $results = $baseQuery
            ->selectRaw(
                '*, (1 - (embedding <=> ?::vector)) as similarity',
                [$embeddingString]
            )
            ->whereRaw(
                'embedding IS NOT NULL AND (1 - (embedding <=> ?::vector)) > ?',
                [$embeddingString, $threshold]
            )
            ->orderByRaw('similarity DESC')
            ->limit($topK)
            ->get();

        $scores = $results->mapWithKeys(fn ($chunk) => [
            $chunk->id => (float) $chunk->similarity,
        ])->toArray();

        return new RetrievalResult(
            chunks: $results,
            strategy: 'dense',
            scores: $scores,
        );
    }

    /**
     * Sparse search (BM25-like keyword matching).
     *
     * Uses PostgreSQL full-text search.
     *
     * @param  string  $query  The search query
     * @param  Collection|array|Document  $documents  Documents to search within
     * @param  array<string, mixed>  $options  Search options
     */
    public function sparseSearch(
        string $query,
        Collection|array|Document $documents,
        array $options = []
    ): RetrievalResult {
        $topK = $options['top_k'] ?? config('ai.rag.top_k', 10);

        // Build base query
        $baseQuery = $this->buildBaseQuery($documents);

        // Use PostgreSQL full-text search
        $results = $baseQuery
            ->selectRaw(
                "*, ts_rank(to_tsvector('english', content), plainto_tsquery('english', ?)) as rank",
                [$query]
            )
            ->whereRaw(
                "to_tsvector('english', content) @@ plainto_tsquery('english', ?)",
                [$query]
            )
            ->orderByRaw('rank DESC')
            ->limit($topK)
            ->get();

        $scores = $results->mapWithKeys(fn ($chunk) => [
            $chunk->id => (float) $chunk->rank,
        ])->toArray();

        return new RetrievalResult(
            chunks: $results,
            strategy: 'sparse',
            scores: $scores,
        );
    }

    /**
     * Hybrid search: dense + sparse with RRF (Reciprocal Rank Fusion).
     *
     * This is the recommended default strategy.
     *
     * @param  string  $query  The search query
     * @param  Collection|array|Document  $documents  Documents to search within
     * @param  array<string, mixed>  $options  Search options
     */
    public function hybridSearch(
        string $query,
        Collection|array|Document $documents,
        array $options = []
    ): RetrievalResult {
        $topK = $options['top_k'] ?? config('ai.rag.top_k', 10);
        // Fetch more results for fusion
        $options['top_k'] = $topK * 2;

        $denseResults = $this->denseSearch($query, $documents, $options);
        $sparseResults = $this->sparseSearch($query, $documents, $options);

        // RRF: score = 1/(rank + k), where k is typically 60
        $rrfK = 60;
        $fusedScores = [];

        foreach ($denseResults->chunks as $rank => $chunk) {
            $score = 1 / ($rank + $rrfK);
            $fusedScores[$chunk->id] = ($fusedScores[$chunk->id] ?? 0) + $score;
        }

        foreach ($sparseResults->chunks as $rank => $chunk) {
            $score = 1 / ($rank + $rrfK);
            $fusedScores[$chunk->id] = ($fusedScores[$chunk->id] ?? 0) + $score;
        }

        // Sort by fused score and get top K
        arsort($fusedScores);
        $topIds = \array_slice(array_keys($fusedScores), 0, $topK);

        // Fetch chunks in score order
        $chunks = collect();
        foreach ($topIds as $id) {
            $chunk = DocumentChunk::with('document')->find($id);
            if ($chunk) {
                $chunks->push($chunk);
            }
        }

        $scores = \array_slice($fusedScores, 0, $topK, true);

        return new RetrievalResult(
            chunks: $chunks,
            strategy: 'hybrid',
            scores: $scores,
        );
    }

    /**
     * Build base query with document filtering.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildBaseQuery(Collection|array|Document $documents)
    {
        $query = DocumentChunk::with('document')
            ->whereNotNull('embedding_generated_at');

        if ($documents instanceof Document) {
            $query->where('document_id', $documents->id);
        } elseif ($documents instanceof Collection) {
            $query->whereIn('document_id', $documents->pluck('id')->toArray());
        } elseif (\is_array($documents)) {
            $ids = array_map(fn ($d) => $d instanceof Document ? $d->id : $d, $documents);
            $query->whereIn('document_id', $ids);
        }

        return $query;
    }

    /**
     * Format an embedding array for pgvector.
     *
     * @param  array<float>  $embedding
     */
    protected function formatVectorForPgvector(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
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
            // Don't fail retrieval if logging fails
            report($e);
        }
    }
}
