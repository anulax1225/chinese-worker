<?php

namespace App\DTOs;

use Illuminate\Support\Collection;

readonly class SearchResult
{
    public function __construct(
        public Collection $items,
        public string $strategy,
        public array $scores,
        public float $executionTimeMs = 0.0,
    ) {}

    public function hasItems(): bool
    {
        return $this->items->isNotEmpty();
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function averageScore(): float
    {
        if (empty($this->scores)) {
            return 0.0;
        }

        return array_sum($this->scores) / count($this->scores);
    }

    public function getItemIds(): array
    {
        return $this->items->pluck('id')->toArray();
    }

    public static function empty(string $strategy = 'hybrid'): self
    {
        return new self(
            items: collect(),
            strategy: $strategy,
            scores: [],
            executionTimeMs: 0.0,
        );
    }
}
