<?php

namespace App\Services\Search;

use App\DTOs\Search\SearchQuery;
use App\DTOs\Search\SearchResult;
use App\DTOs\Search\SearchResultCollection;

class SearchResultNormalizer
{
    /**
     * Normalize raw search results into a collection.
     *
     * @param  array<int, array<string, mixed>>  $rawResults
     */
    public function normalize(array $rawResults, SearchQuery $query, float $searchTime = 0.0): SearchResultCollection
    {
        $results = [];

        foreach ($rawResults as $item) {
            // Skip items without a URL
            if (empty($item['url'])) {
                continue;
            }

            $results[] = SearchResult::fromSearXNG($item);

            // Stop if we have enough results
            if (count($results) >= $query->maxResults) {
                break;
            }
        }

        return new SearchResultCollection(
            results: $results,
            query: $query->query,
            searchTime: $searchTime,
            fromCache: false,
        );
    }

    /**
     * Normalize a single raw result into a SearchResult.
     *
     * @param  array<string, mixed>  $rawResult
     */
    public function normalizeOne(array $rawResult): SearchResult
    {
        return SearchResult::fromSearXNG($rawResult);
    }
}
