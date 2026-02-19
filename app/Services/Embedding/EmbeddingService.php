<?php

namespace App\Services\Embedding;

use App\Contracts\AIBackendInterface;
use App\Models\EmbeddingCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EmbeddingService
{
    public function __construct(
        private AIBackendInterface $backend,
    ) {}

    /**
     * Embed a single text.
     *
     * @param  string  $text  The text to embed
     * @param  string|null  $model  Optional model override
     * @return array<float> The embedding vector
     */
    public function embed(string $text, ?string $model = null): array
    {
        $model = $model ?? $this->getDefaultModel();

        if (config('ai.rag.cache_embeddings', true)) {
            $cached = $this->getFromCache($text, $model);
            if ($cached !== null) {
                return $cached;
            }
        }

        $embedding = $this->generateEmbedding($text, $model);

        if (config('ai.rag.cache_embeddings', true)) {
            $this->storeInCache($text, $embedding, $model);
        }

        return $embedding;
    }

    /**
     * Embed multiple texts (batch processing).
     *
     * @param  array<string>  $texts  Array of texts to embed
     * @param  string|null  $model  Optional model override
     * @return array<array<float>> Array of embedding vectors
     */
    public function embedBatch(array $texts, ?string $model = null): array
    {
        $model = $model ?? $this->getDefaultModel();
        $embeddings = [];
        $toEmbed = [];
        $toEmbedIndices = [];

        // Check cache for each text
        foreach ($texts as $index => $text) {
            if (config('ai.rag.cache_embeddings', true)) {
                $cached = $this->getFromCache($text, $model);
                if ($cached !== null) {
                    $embeddings[$index] = $cached;

                    continue;
                }
            }
            $toEmbed[] = $text;
            $toEmbedIndices[] = $index;
        }

        // Batch generate uncached embeddings
        if (! empty($toEmbed)) {
            $batchSize = config('ai.rag.embedding_batch_size', 100);
            $chunks = array_chunk($toEmbed, $batchSize);
            $chunkIndices = array_chunk($toEmbedIndices, $batchSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                $newEmbeddings = $this->backend->generateEmbeddings($chunk, $model);

                foreach ($newEmbeddings as $i => $embedding) {
                    $originalIndex = $chunkIndices[$chunkIndex][$i];
                    $embeddings[$originalIndex] = $embedding;

                    if (config('ai.rag.cache_embeddings', true)) {
                        $this->storeInCache($chunk[$i], $embedding, $model);
                    }
                }
            }
        }

        ksort($embeddings);

        return array_values($embeddings);
    }

    /**
     * Generate sparse embedding (BM25-like term frequencies).
     *
     * Used for hybrid search across all searchable models.
     *
     * @param  string  $text  The text to process
     * @return array<string, float> Term frequencies
     */
    public function generateSparseEmbedding(string $text): array
    {
        $tokens = str_word_count(strtolower($text), 1);

        $stopwords = [
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been',
            'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'need',
            'it', 'its', 'this', 'that', 'these', 'those', 'i', 'you', 'he',
            'she', 'we', 'they', 'what', 'which', 'who', 'when', 'where', 'why',
            'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other',
            'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so',
            'than', 'too', 'very', 'just', 'also',
        ];

        $tokens = array_filter($tokens, fn ($t) => ! \in_array($t, $stopwords, true) && \strlen($t) > 2);

        $termFrequencies = array_count_values($tokens);

        if (empty($termFrequencies)) {
            return [];
        }

        $maxFreq = max($termFrequencies);
        $normalized = [];

        foreach ($termFrequencies as $term => $freq) {
            $normalized[$term] = round($freq / $maxFreq, 3);
        }

        return $normalized;
    }

    /**
     * Get the embedding dimensions for the current model.
     */
    public function getEmbeddingDimensions(?string $model = null): int
    {
        return $this->backend->getEmbeddingDimensions($model ?? $this->getDefaultModel());
    }

    /**
     * Format an embedding array for pgvector.
     *
     * @param  array<float>  $embedding
     */
    public function formatVectorForPgvector(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }

    /**
     * Check if we're using PostgreSQL with pgvector.
     */
    public function usesPgvector(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Get the default embedding model from config.
     */
    protected function getDefaultModel(): string
    {
        return config('ai.rag.embedding_model', 'text-embedding-3-small');
    }

    /**
     * Generate a single embedding via the backend.
     *
     * @return array<float>
     */
    protected function generateEmbedding(string $text, string $model): array
    {
        $result = $this->backend->generateEmbeddings([$text], $model);

        if (empty($result)) {
            throw new RuntimeException('Failed to generate embedding: empty result');
        }

        return $result[0];
    }

    /**
     * Get embedding from cache.
     *
     * @return array<float>|null
     */
    protected function getFromCache(string $text, string $model): ?array
    {
        $hash = $this->hashContent($text, $model);

        $cached = EmbeddingCache::where('content_hash', $hash)
            ->where('embedding_model', $model)
            ->first();

        if ($cached && $cached->embedding_raw) {
            return $cached->embedding_raw;
        }

        return null;
    }

    /**
     * Store embedding in cache.
     *
     * @param  array<float>  $embedding
     */
    protected function storeInCache(string $text, array $embedding, string $model): void
    {
        $hash = $this->hashContent($text, $model);
        $embeddingString = $this->formatVectorForPgvector($embedding);

        $cache = EmbeddingCache::updateOrCreate(
            [
                'content_hash' => $hash,
                'embedding_model' => $model,
            ],
            [
                'embedding_raw' => $embedding,
            ]
        );

        if ($this->usesPgvector() && \count($embedding) === 1536) {
            DB::statement(
                'UPDATE embedding_cache SET embedding = ?::vector WHERE id = ?',
                [$embeddingString, $cache->id]
            );
        }
    }

    /**
     * Hash content for cache key.
     */
    protected function hashContent(string $text, string $model): string
    {
        return hash('sha256', "{$text}::{$model}");
    }
}
