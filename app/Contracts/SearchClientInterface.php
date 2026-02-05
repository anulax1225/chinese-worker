<?php

namespace App\Contracts;

use App\DTOs\Search\SearchQuery;

interface SearchClientInterface
{
    /**
     * Execute a search query and return raw results.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(SearchQuery $query): array;

    /**
     * Check if the search service is available.
     */
    public function isAvailable(): bool;

    /**
     * Get the name of this search client.
     */
    public function getName(): string;
}
