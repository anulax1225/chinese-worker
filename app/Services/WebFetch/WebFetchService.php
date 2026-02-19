<?php

namespace App\Services\WebFetch;

use App\Contracts\WebFetchClientInterface;
use App\DTOs\WebFetch\FetchedDocument;
use App\DTOs\WebFetch\FetchRequest;
use App\Exceptions\WebFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class WebFetchService
{
    public function __construct(
        private readonly WebFetchClientInterface $client,
        private readonly ContentExtractor $extractor,
        private readonly ?FetchCache $cache = null,
        private readonly ?FetchedPageStore $fetchedPageStore = null,
    ) {}

    /**
     * Fetch and extract content from a URL.
     */
    public function fetch(FetchRequest $request): FetchedDocument
    {
        // Validate request
        if (! $request->isValid()) {
            throw WebFetchException::invalidUrl($request->url);
        }

        // Check cache first
        if ($this->cache) {
            $cached = $this->cache->get($request);
            if ($cached !== null) {
                Log::info('WebFetch cache hit', [
                    'url' => $request->url,
                    'title' => $cached->title,
                ]);

                return $cached;
            }
        }

        // Execute fetch with retry logic
        $startTime = microtime(true);
        $retryTimes = config('webfetch.retry.times', 2);
        $retrySleepMs = config('webfetch.retry.sleep_ms', 500);

        try {
            $rawResponse = retry(
                times: $retryTimes,
                callback: fn () => $this->client->fetch($request),
                sleepMilliseconds: $retrySleepMs,
                when: fn (\Throwable $e) => $e instanceof ConnectionException
            );
        } catch (WebFetchException $e) {
            Log::error('WebFetch failed', [
                'url' => $request->url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('WebFetch failed after retries', [
                'url' => $request->url,
                'error' => $e->getMessage(),
                'retries' => $retryTimes,
            ]);
            throw WebFetchException::connectionFailed($request->url, $e);
        }

        $fetchTime = microtime(true) - $startTime;

        // Extract content
        $document = $this->extractor->extract($rawResponse, $request->url, $fetchTime);

        // Log metrics
        Log::info('WebFetch completed', [
            'url' => $request->url,
            'title' => $document->title,
            'text_length' => $document->textLength(),
            'content_type' => $document->contentType,
            'duration_ms' => round($fetchTime * 1000, 2),
            'cache_hit' => false,
            'client' => $this->client->getName(),
        ]);

        // Cache result if document has content
        if ($this->cache && $document->hasContent()) {
            $this->cache->put($request, $document);
        }

        // Persist to DB for future semantic search (fire-and-forget, never blocks fetch)
        if ($this->fetchedPageStore && $document->hasContent()) {
            try {
                $this->fetchedPageStore->persist($document);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $document;
    }

    /**
     * Check if the fetch service is available.
     */
    public function isAvailable(): bool
    {
        return $this->client->isAvailable();
    }

    /**
     * Get the name of the underlying client.
     */
    public function getClientName(): string
    {
        return $this->client->getName();
    }

    /**
     * Create instance from configuration.
     */
    public static function make(): self
    {
        $client = HttpFetchClient::fromConfig();
        $extractor = ContentExtractor::fromConfig();
        $cache = config('webfetch.cache.enabled', true)
            ? FetchCache::fromConfig()
            : null;
        $store = config('ai.rag.enabled', false)
            ? app(FetchedPageStore::class)
            : null;

        return new self($client, $extractor, $cache, $store);
    }
}
