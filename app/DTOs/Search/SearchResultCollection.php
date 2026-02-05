<?php

namespace App\DTOs\Search;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, SearchResult>
 */
class SearchResultCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<SearchResult>  $results
     * @param  string  $query  Original search query
     * @param  float  $searchTime  Time taken in seconds
     * @param  bool  $fromCache  Whether results came from cache
     */
    public function __construct(
        private array $results,
        public readonly string $query,
        public readonly float $searchTime = 0.0,
        public readonly bool $fromCache = false,
    ) {}

    /**
     * Get number of results.
     */
    public function count(): int
    {
        return count($this->results);
    }

    /**
     * Check if collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->results);
    }

    /**
     * Get iterator for foreach.
     *
     * @return Traversable<int, SearchResult>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }

    /**
     * Get all results as array.
     *
     * @return array<SearchResult>
     */
    public function all(): array
    {
        return $this->results;
    }

    /**
     * Take first N results.
     */
    public function take(int $count): self
    {
        return new self(
            results: array_slice($this->results, 0, $count),
            query: $this->query,
            searchTime: $this->searchTime,
            fromCache: $this->fromCache,
        );
    }

    /**
     * Filter results by engine.
     */
    public function filterByEngine(string $engine): self
    {
        return new self(
            results: array_values(array_filter(
                $this->results,
                fn (SearchResult $result) => $result->engine === $engine
            )),
            query: $this->query,
            searchTime: $this->searchTime,
            fromCache: $this->fromCache,
        );
    }

    /**
     * Get first result or null.
     */
    public function first(): ?SearchResult
    {
        return $this->results[0] ?? null;
    }

    /**
     * Convert to array of result arrays.
     *
     * @return array<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            fn (SearchResult $result) => $result->toArray(),
            $this->results
        );
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode([
            'query' => $this->query,
            'results' => $this->toArray(),
            'count' => $this->count(),
            'search_time_ms' => round($this->searchTime * 1000, 2),
            'from_cache' => $this->fromCache,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Create a cached copy of this collection.
     */
    public function withCacheFlag(): self
    {
        return new self(
            results: $this->results,
            query: $this->query,
            searchTime: $this->searchTime,
            fromCache: true,
        );
    }
}
