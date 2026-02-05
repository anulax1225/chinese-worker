<?php

namespace App\DTOs\Search;

readonly class SearchQuery
{
    /**
     * @param  string  $query  The search query string
     * @param  int  $maxResults  Maximum number of results to return
     * @param  array<string>|null  $engines  Specific engines to use (null = default)
     * @param  string|null  $language  Language code for results
     * @param  string|null  $timeRange  Time range filter: day, week, month, year
     */
    public function __construct(
        public string $query,
        public int $maxResults = 5,
        public ?array $engines = null,
        public ?string $language = null,
        public ?string $timeRange = null,
    ) {}

    /**
     * Generate a cache key for this query.
     */
    public function cacheKey(): string
    {
        return md5(json_encode([
            'q' => strtolower(trim($this->query)),
            'max' => $this->maxResults,
            'engines' => $this->engines,
            'lang' => $this->language,
            'time' => $this->timeRange,
        ]));
    }

    /**
     * Check if the query is valid (non-empty).
     */
    public function isValid(): bool
    {
        return ! empty(trim($this->query));
    }

    /**
     * Convert to array for API requests.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'max_results' => $this->maxResults,
            'engines' => $this->engines,
            'language' => $this->language,
            'time_range' => $this->timeRange,
        ];
    }
}
