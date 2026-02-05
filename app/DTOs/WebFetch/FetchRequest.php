<?php

namespace App\DTOs\WebFetch;

readonly class FetchRequest
{
    public function __construct(
        public string $url,
        public ?int $timeout = null,
        public ?int $maxSize = null,
    ) {}

    /**
     * Generate a cache key for this request.
     */
    public function cacheKey(): string
    {
        $normalizedUrl = $this->normalizeUrl($this->url);

        return md5($normalizedUrl);
    }

    /**
     * Validate the request URL.
     */
    public function isValid(): bool
    {
        if (empty(trim($this->url))) {
            return false;
        }

        $url = filter_var($this->url, FILTER_VALIDATE_URL);

        if ($url === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'timeout' => $this->timeout,
            'max_size' => $this->maxSize,
        ];
    }

    /**
     * Normalize URL for consistent cache keys.
     */
    protected function normalizeUrl(string $url): string
    {
        $url = strtolower(trim($url));

        // Remove trailing slash
        $url = rtrim($url, '/');

        // Remove default ports
        $url = preg_replace('/:80(?=\/|$)/', '', $url);
        $url = preg_replace('/:443(?=\/|$)/', '', $url);

        // Sort query parameters for consistency
        $parts = parse_url($url);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            ksort($queryParams);
            $parts['query'] = http_build_query($queryParams);
        }

        return $this->buildUrl($parts);
    }

    /**
     * Rebuild URL from parsed parts.
     *
     * @param  array<string, mixed>  $parts
     */
    protected function buildUrl(array $parts): string
    {
        $url = '';

        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'].'://';
        }

        if (isset($parts['host'])) {
            $url .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        if (isset($parts['path'])) {
            $url .= $parts['path'];
        }

        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
