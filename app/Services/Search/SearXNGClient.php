<?php

namespace App\Services\Search;

use App\Contracts\SearchClientInterface;
use App\DTOs\Search\SearchQuery;
use App\Exceptions\SearchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearXNGClient implements SearchClientInterface
{
    /**
     * @param  array<string>  $defaultEngines
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 10,
        private readonly array $defaultEngines = ['google', 'bing', 'duckduckgo'],
        private readonly int $safeSearch = 1,
    ) {}

    /**
     * Create instance from config.
     */
    public static function fromConfig(): self
    {
        $config = config('search.searxng');

        return new self(
            baseUrl: $config['base_url'],
            timeout: $config['timeout'],
            defaultEngines: $config['engines'],
            safeSearch: $config['safe_search'],
        );
    }

    /**
     * Execute a search query.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SearchException
     */
    public function search(SearchQuery $query): array
    {
        if (! $query->isValid()) {
            throw SearchException::invalidQuery();
        }

        try {
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/search", [
                    'q' => $query->query,
                    'format' => 'json',
                    'engines' => implode(',', $query->engines ?? $this->defaultEngines),
                    'safesearch' => $this->safeSearch,
                    'language' => $query->language ?? 'auto',
                    'time_range' => $query->timeRange,
                ]);

            if (! $response->successful()) {
                Log::warning('SearXNG returned error status', [
                    'status' => $response->status(),
                    'query' => $query->query,
                ]);

                throw SearchException::invalidResponse("HTTP status: {$response->status()}");
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw SearchException::invalidResponse('Response is not valid JSON');
            }

            return $data['results'] ?? [];

        } catch (ConnectionException $e) {
            Log::error('SearXNG connection failed', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            throw SearchException::connectionFailed($this->baseUrl, $e);
        }
    }

    /**
     * Check if SearXNG is available.
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/healthz");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get client name.
     */
    public function getName(): string
    {
        return 'searxng';
    }
}
