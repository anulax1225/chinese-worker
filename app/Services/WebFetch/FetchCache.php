<?php

namespace App\Services\WebFetch;

use App\DTOs\WebFetch\FetchedDocument;
use App\DTOs\WebFetch\FetchRequest;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchCache
{
    public function __construct(
        protected CacheRepository $cache,
        protected int $ttl = 1800,
        protected string $prefix = 'webfetch:',
    ) {}

    /**
     * Get cached document for request.
     */
    public function get(FetchRequest $request): ?FetchedDocument
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $key = $this->buildKey($request);
        $cached = $this->cache->get($key);

        if ($cached === null) {
            return null;
        }

        Log::debug('WebFetch cache hit', ['url' => $request->url, 'key' => $key]);

        return $this->deserialize($cached);
    }

    /**
     * Cache a fetched document.
     */
    public function put(FetchRequest $request, FetchedDocument $document): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = $this->buildKey($request);
        $data = $this->serialize($document);

        $this->cache->put($key, $data, $this->ttl);

        Log::debug('WebFetch cache put', ['url' => $request->url, 'key' => $key, 'ttl' => $this->ttl]);
    }

    /**
     * Remove cached document.
     */
    public function forget(FetchRequest $request): void
    {
        $key = $this->buildKey($request);
        $this->cache->forget($key);

        Log::debug('WebFetch cache forget', ['url' => $request->url, 'key' => $key]);
    }

    /**
     * Check if caching is enabled.
     */
    public function isEnabled(): bool
    {
        return config('webfetch.cache.enabled', true);
    }

    /**
     * Build cache key for request.
     */
    protected function buildKey(FetchRequest $request): string
    {
        return $this->prefix.$request->cacheKey();
    }

    /**
     * Serialize document for caching.
     *
     * @return array<string, mixed>
     */
    protected function serialize(FetchedDocument $document): array
    {
        return [
            'url' => $document->url,
            'title' => $document->title,
            'text' => $document->text,
            'content_type' => $document->contentType,
            'fetch_time' => $document->fetchTime,
            'metadata' => $document->metadata,
            'cached_at' => time(),
        ];
    }

    /**
     * Deserialize cached data to document.
     *
     * @param  array<string, mixed>  $data
     */
    protected function deserialize(array $data): FetchedDocument
    {
        return new FetchedDocument(
            url: $data['url'],
            title: $data['title'],
            text: $data['text'],
            contentType: $data['content_type'],
            fetchTime: $data['fetch_time'],
            fromCache: true,
            metadata: array_merge($data['metadata'] ?? [], [
                'cached_at' => $data['cached_at'] ?? null,
            ]),
        );
    }

    /**
     * Create instance from config.
     */
    public static function fromConfig(): self
    {
        $store = config('webfetch.cache.store', 'redis');

        return new self(
            cache: Cache::store($store),
            ttl: config('webfetch.cache.ttl', 1800),
            prefix: config('webfetch.cache.prefix', 'webfetch:'),
        );
    }
}
