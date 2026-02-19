<?php

namespace App\Services\Embedding;

use App\DTOs\SearchResult;
use Illuminate\Database\Eloquent\Builder;

class VectorSearchService
{
    public function __construct(
        private EmbeddingService $embeddingService,
    ) {}

    /**
     * Search using the configured strategy.
     *
     * The caller is responsible for scoping the Builder to the correct records.
     * This service only applies the vector math on top of that scope.
     *
     * @param  array<string, mixed>  $options  Supports: strategy, embedding_model, top_k, threshold
     */
    public function search(string $query, Builder $source, array $options = []): SearchResult
    {
        $strategy = $options['strategy'] ?? config('ai.rag.search_type', 'hybrid');
        $startTime = microtime(true);

        $result = match ($strategy) {
            'dense' => $this->denseSearch($query, $source, $options),
            'sparse' => $this->sparseSearch($query, $source, $options),
            'hybrid' => $this->hybridSearch($query, $source, $options),
            default => $this->hybridSearch($query, $source, $options),
        };

        $executionTimeMs = (microtime(true) - $startTime) * 1000;

        return new SearchResult(
            items: $result->items,
            strategy: $result->strategy,
            scores: $result->scores,
            executionTimeMs: $executionTimeMs,
        );
    }

    /**
     * Dense vector search using cosine similarity.
     *
     * Uses pgvector native search with PHP cosine fallback.
     */
    protected function denseSearch(string $query, Builder $source, array $options): SearchResult
    {
        $model = $options['embedding_model'] ?? config('ai.rag.embedding_model');
        $topK = $options['top_k'] ?? config('ai.rag.top_k', 10);
        $threshold = $options['threshold'] ?? config('ai.rag.similarity_threshold', 0.3);

        $queryEmbedding = $this->embeddingService->embed($query, $model);

        if ($this->embeddingService->usesPgvector()) {
            $embeddingString = $this->embeddingService->formatVectorForPgvector($queryEmbedding);

            $results = (clone $source)
                ->whereNotNull('embedding_generated_at')
                ->selectRaw('*, (1 - (embedding <=> ?::vector)) as similarity', [$embeddingString])
                ->whereRaw(
                    'embedding IS NOT NULL AND (1 - (embedding <=> ?::vector)) > ?',
                    [$embeddingString, $threshold]
                )
                ->orderByRaw('similarity DESC')
                ->limit($topK)
                ->get();

            if ($results->isNotEmpty()) {
                $scores = $results->mapWithKeys(fn ($item) => [
                    $item->id => (float) $item->similarity,
                ])->toArray();

                return new SearchResult(items: $results, strategy: 'dense', scores: $scores);
            }
        }

        // PHP cosine fallback
        $candidates = (clone $source)
            ->whereNotNull('embedding_raw')
            ->whereNotNull('embedding_generated_at')
            ->get();

        $scores = [];
        foreach ($candidates as $item) {
            $sim = $this->cosineSimilarity($queryEmbedding, $item->embedding_raw);
            if ($sim > $threshold) {
                $scores[$item->id] = $sim;
            }
        }

        arsort($scores);
        $topIds = \array_slice(array_keys($scores), 0, $topK);
        $scores = \array_slice($scores, 0, $topK, true);
        $results = $candidates->whereIn('id', $topIds)
            ->sortBy(fn ($item) => array_search($item->id, $topIds))
            ->values();

        return new SearchResult(items: $results, strategy: 'dense', scores: $scores);
    }

    /**
     * Sparse search using pre-computed sparse_vector JSONB dot product.
     *
     * Does not require a content column — works uniformly across all models.
     */
    protected function sparseSearch(string $query, Builder $source, array $options): SearchResult
    {
        $topK = $options['top_k'] ?? config('ai.rag.top_k', 10);

        $querySparse = $this->embeddingService->generateSparseEmbedding($query);

        if (empty($querySparse)) {
            return SearchResult::empty('sparse');
        }

        // Use GIN index to filter candidates that share at least one term
        $queryKeys = array_keys($querySparse);

        $candidates = (clone $source)
            ->whereNotNull('sparse_vector')
            ->where(function (Builder $q) use ($queryKeys): void {
                foreach ($queryKeys as $key) {
                    $q->orWhereRaw('sparse_vector ?? ?', [$key]);
                }
            })
            ->get();

        if ($candidates->isEmpty()) {
            return SearchResult::empty('sparse');
        }

        // Score via dot product in PHP
        $scores = [];
        foreach ($candidates as $item) {
            $storedSparse = $item->sparse_vector ?? [];
            $score = $this->dotProduct($querySparse, $storedSparse);
            if ($score > 0) {
                $scores[$item->id] = $score;
            }
        }

        arsort($scores);
        $topIds = \array_slice(array_keys($scores), 0, $topK);
        $scores = \array_slice($scores, 0, $topK, true);
        $results = $candidates->whereIn('id', $topIds)
            ->sortBy(fn ($item) => array_search($item->id, $topIds))
            ->values();

        return new SearchResult(items: $results, strategy: 'sparse', scores: $scores);
    }

    /**
     * Hybrid search: dense + sparse merged via Reciprocal Rank Fusion.
     */
    protected function hybridSearch(string $query, Builder $source, array $options): SearchResult
    {
        $topK = $options['top_k'] ?? config('ai.rag.top_k', 10);
        $options['top_k'] = $topK * 2;

        $denseResult = $this->denseSearch($query, $source, $options);
        $sparseResult = $this->sparseSearch($query, $source, $options);

        $rrfK = 60;
        $fusedScores = [];

        foreach ($denseResult->items as $rank => $item) {
            $fusedScores[$item->id] = ($fusedScores[$item->id] ?? 0) + 1 / ($rank + $rrfK);
        }

        foreach ($sparseResult->items as $rank => $item) {
            $fusedScores[$item->id] = ($fusedScores[$item->id] ?? 0) + 1 / ($rank + $rrfK);
        }

        arsort($fusedScores);
        $topIds = \array_slice(array_keys($fusedScores), 0, $topK);
        $scores = \array_slice($fusedScores, 0, $topK, true);

        // Merge items from both result sets, preserving fused order
        $allItems = $denseResult->items->concat($sparseResult->items)->keyBy('id');
        $results = collect($topIds)
            ->map(fn ($id) => $allItems->get($id))
            ->filter()
            ->values();

        return new SearchResult(items: $results, strategy: 'hybrid', scores: $scores);
    }

    /**
     * Compute cosine similarity between two vectors.
     *
     * @param  array<float>  $a
     * @param  array<float>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        if (\count($a) !== \count($b) || empty($a)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $len = \count($a); $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator > 0 ? $dotProduct / $denominator : 0.0;
    }

    /**
     * Compute dot product between two sparse vectors (associative arrays).
     *
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     */
    protected function dotProduct(array $a, array $b): float
    {
        $score = 0.0;

        foreach ($a as $key => $value) {
            if (isset($b[$key])) {
                $score += $value * $b[$key];
            }
        }

        return $score;
    }
}
