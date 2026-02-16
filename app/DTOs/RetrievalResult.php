<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;

readonly class RetrievalResult
{
    /**
     * @param  Collection  $chunks  Collection of DocumentChunk models
     * @param  array<int, float>  $scores  Chunk ID => similarity score
     */
    public function __construct(
        public Collection $chunks,
        public string $strategy,
        public array $scores = [],
        public float $executionTimeMs = 0,
    ) {}

    /**
     * Get total chunks retrieved.
     */
    public function count(): int
    {
        return $this->chunks->count();
    }

    /**
     * Get average score of retrieved chunks.
     */
    public function averageScore(): float
    {
        if (empty($this->scores)) {
            return 0.0;
        }

        return array_sum($this->scores) / \count($this->scores);
    }

    /**
     * Get score for a specific chunk.
     */
    public function getScore(int $chunkId): ?float
    {
        return $this->scores[$chunkId] ?? null;
    }

    /**
     * Filter chunks by minimum score threshold.
     */
    public function withMinScore(float $threshold): self
    {
        $filtered = $this->chunks->filter(function ($chunk) use ($threshold) {
            $score = $this->getScore($chunk->id);

            return $score === null || $score >= $threshold;
        });

        $filteredScores = array_filter(
            $this->scores,
            fn ($score) => $score >= $threshold
        );

        return new self(
            chunks: $filtered,
            strategy: $this->strategy,
            scores: $filteredScores,
            executionTimeMs: $this->executionTimeMs,
        );
    }

    /**
     * Check if any chunks were retrieved.
     */
    public function hasChunks(): bool
    {
        return $this->chunks->isNotEmpty();
    }

    /**
     * Get chunks as an array of IDs.
     *
     * @return array<int>
     */
    public function getChunkIds(): array
    {
        return $this->chunks->pluck('id')->toArray();
    }

    /**
     * Create an empty result.
     */
    public static function empty(string $strategy = 'none'): self
    {
        return new self(
            chunks: collect(),
            strategy: $strategy,
            scores: [],
            executionTimeMs: 0,
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
            'chunk_count' => $this->count(),
            'strategy' => $this->strategy,
            'average_score' => round($this->averageScore(), 4),
            'execution_time_ms' => round($this->executionTimeMs, 2),
            'chunk_ids' => $this->getChunkIds(),
        ];
    }
}
