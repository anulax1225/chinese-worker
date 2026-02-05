<?php

namespace App\DTOs\Search;

readonly class SearchResult
{
    /**
     * @param  string  $title  Result title
     * @param  string  $url  Result URL
     * @param  string  $snippet  Text snippet/description
     * @param  string|null  $engine  Search engine that returned this result
     * @param  float|null  $score  Relevance score (if provided)
     * @param  string|null  $publishedDate  Publication date (if available)
     * @param  array<string, mixed>  $metadata  Additional metadata
     */
    public function __construct(
        public string $title,
        public string $url,
        public string $snippet,
        public ?string $engine = null,
        public ?float $score = null,
        public ?string $publishedDate = null,
        public array $metadata = [],
    ) {}

    /**
     * Create from a SearXNG result array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromSearXNG(array $data): self
    {
        return new self(
            title: $data['title'] ?? $data['url'] ?? '',
            url: $data['url'] ?? '',
            snippet: $data['content'] ?? '',
            engine: $data['engine'] ?? null,
            score: isset($data['score']) ? (float) $data['score'] : null,
            publishedDate: $data['publishedDate'] ?? null,
            metadata: array_filter([
                'category' => $data['category'] ?? null,
                'thumbnail' => $data['thumbnail'] ?? null,
            ]),
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
            'engine' => $this->engine,
            'score' => $this->score,
            'published_date' => $this->publishedDate,
        ];
    }
}
