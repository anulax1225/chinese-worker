<?php

namespace App\Services\Search;

use App\Contracts\SearchClientInterface;
use App\DTOs\Search\SearchQuery;
use App\DTOs\Search\SearchResultCollection;
use App\Exceptions\SearchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class SearchService
{
    public function __construct(
        private readonly SearchClientInterface $client,
        private readonly SearchResultNormalizer $normalizer,
        private readonly ?SearchCache $cache = null,
    ) {}

    /**
     * Create instance from config with all dependencies.
     */
    public static function make(): self
    {
        $client = SearXNGClient::fromConfig();
        $normalizer = new SearchResultNormalizer;

        $cache = config('search.cache.enabled', true)
            ? SearchCache::fromConfig()
            : null;

        return new self($client, $normalizer, $cache);
    }

    /**
     * Execute a search query.
     *
     * @throws SearchException
     */
    public function search(SearchQuery $query): SearchResultCollection
    {
        if (! $query->isValid()) {
            throw SearchException::invalidQuery();
        }

        // 1. Check cache first
        if ($this->cache) {
            $cached = $this->cache->get($query);
            if ($cached !== null) {
                Log::info('Search cache hit', [
                    'query' => $query->query,
                    'results_count' => $cached->count(),
                ]);

                return $cached;
            }
        }

        // 2. Execute search with retry logic
        $startTime = microtime(true);
        $retryTimes = config('search.retry.times', 2);
        $retrySleepMs = config('search.retry.sleep_ms', 500);

        try {
            $rawResults = retry(
                times: $retryTimes,
                callback: fn () => $this->client->search($query),
                sleepMilliseconds: $retrySleepMs,
                when: fn (\Throwable $e) => $e instanceof ConnectionException
            );
        } catch (SearchException $e) {
            // Re-throw search exceptions as-is
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Search failed after retries', [
                'query' => $query->query,
                'error' => $e->getMessage(),
                'retries' => $retryTimes,
            ]);

            throw SearchException::unavailable($this->client->getName());
        }

        $searchTime = microtime(true) - $startTime;

        // 3. Normalize results
        $results = $this->normalizer->normalize($rawResults, $query, $searchTime);

        // 4. Log metrics
        Log::info('Search completed', [
            'query' => $query->query,
            'results_count' => $results->count(),
            'duration_ms' => round($searchTime * 1000, 2),
            'cache_hit' => false,
            'client' => $this->client->getName(),
        ]);

        // 5. Cache results
        if ($this->cache && ! $results->isEmpty()) {
            $this->cache->put($query, $results);
        }

        return $results;
    }

    /**
     * Check if the search service is available.
     */
    public function isAvailable(): bool
    {
        return $this->client->isAvailable();
    }

    /**
     * Get the underlying client name.
     */
    public function getClientName(): string
    {
        return $this->client->getName();
    }
}
