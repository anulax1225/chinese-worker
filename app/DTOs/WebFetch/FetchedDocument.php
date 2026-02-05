<?php

namespace App\DTOs\WebFetch;

readonly class FetchedDocument
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $url,
        public string $title,
        public string $text,
        public string $contentType,
        public float $fetchTime,
        public bool $fromCache = false,
        public array $metadata = [],
    ) {}

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'text' => $this->text,
            'content_type' => $this->contentType,
            'fetch_time_ms' => round($this->fetchTime * 1000, 2),
            'from_cache' => $this->fromCache,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Create a truncated version of the document.
     */
    public function truncate(int $maxLength): self
    {
        if (strlen($this->text) <= $maxLength) {
            return $this;
        }

        $truncatedText = mb_substr($this->text, 0, $maxLength);

        // Try to cut at last word boundary
        $lastSpace = mb_strrpos($truncatedText, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.8) {
            $truncatedText = mb_substr($truncatedText, 0, $lastSpace);
        }

        $truncatedText .= '... [truncated]';

        return new self(
            url: $this->url,
            title: $this->title,
            text: $truncatedText,
            contentType: $this->contentType,
            fetchTime: $this->fetchTime,
            fromCache: $this->fromCache,
            metadata: array_merge($this->metadata, ['truncated' => true]),
        );
    }

    /**
     * Create a copy with the cache flag set.
     */
    public function withCacheFlag(): self
    {
        return new self(
            url: $this->url,
            title: $this->title,
            text: $this->text,
            contentType: $this->contentType,
            fetchTime: $this->fetchTime,
            fromCache: true,
            metadata: $this->metadata,
        );
    }

    /**
     * Check if document has content.
     */
    public function hasContent(): bool
    {
        return ! empty(trim($this->text));
    }

    /**
     * Get text length.
     */
    public function textLength(): int
    {
        return strlen($this->text);
    }
}
