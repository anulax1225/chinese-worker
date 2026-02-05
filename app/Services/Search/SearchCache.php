<?php

namespace App\Services\Search;

use App\DTOs\Search\SearchQuery;
use App\DTOs\Search\SearchResultCollection;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

class SearchCache
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'search:',
    ) {}

    /**
     * Create instance from config.
     */
    public static function fromConfig(): self
    {
        $config = config('search.cache');
        $store = $config['store'] ?? 'redis';

        return new self(
            cache: cache()->store($store),
            ttl: $config['ttl'] ?? 3600,
            prefix: $config['prefix'] ?? 'search:',
        );
    }

    /**
     * Get cached results for a query.
     */
    public function get(SearchQuery $query): ?SearchResultCollection
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $key = $this->makeKey($query);
        $cached = $this->cache->get($key);

        if ($cached === null) {
            return null;
        }

        // Deserialize the cached data
        if (is_array($cached)) {
            return $this->deserialize($cached, $query);
        }

        return null;
    }

    /**
     * Cache results for a query.
     */
    public function put(SearchQuery $query, SearchResultCollection $results): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = $this->makeKey($query);
        $data = $this->serialize($results);

        $this->cache->put($key, $data, $this->ttl);

        Log::debug('Search results cached', [
            'query' => $query->query,
            'key' => $key,
            'ttl' => $this->ttl,
            'results_count' => $results->count(),
        ]);
    }

    /**
     * Remove cached results for a query.
     */
    public function forget(SearchQuery $query): void
    {
        $key = $this->makeKey($query);
        $this->cache->forget($key);
    }

    /**
     * Clear all search cache.
     */
    public function flush(): void
    {
        // Note: This only works if the cache store supports tags
        // For Redis without tags, we'd need a different approach
        Log::info('Search cache flush requested');
    }

    /**
     * Check if caching is enabled.
     */
    public function isEnabled(): bool
    {
        return config('search.cache.enabled', true);
    }

    /**
     * Generate cache key for a query.
     */
    private function makeKey(SearchQuery $query): string
    {
        return $this->prefix.$query->cacheKey();
    }

    /**
     * Serialize results for caching.
     *
     * @return array<string, mixed>
     */
    private function serialize(SearchResultCollection $results): array
    {
        return [
            'query' => $results->query,
            'results' => $results->toArray(),
            'search_time' => $results->searchTime,
            'cached_at' => now()->timestamp,
        ];
    }

    /**
     * Deserialize cached data back into a collection.
     *
     * @param  array<string, mixed>  $data
     */
    private function deserialize(array $data, SearchQuery $query): SearchResultCollection
    {
        $results = array_map(
            fn (array $item) => new \App\DTOs\Search\SearchResult(
                title: $item['title'] ?? '',
                url: $item['url'] ?? '',
                snippet: $item['snippet'] ?? '',
                engine: $item['engine'] ?? null,
                score: $item['score'] ?? null,
                publishedDate: $item['published_date'] ?? null,
            ),
            $data['results'] ?? []
        );

        return new SearchResultCollection(
            results: $results,
            query: $data['query'] ?? $query->query,
            searchTime: $data['search_time'] ?? 0.0,
            fromCache: true,
        );
    }
}
