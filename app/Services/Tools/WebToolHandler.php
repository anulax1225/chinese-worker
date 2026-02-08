<?php

namespace App\Services\Tools;

use App\DTOs\Search\SearchQuery;
use App\DTOs\ToolResult;
use App\DTOs\WebFetch\FetchRequest;
use App\Exceptions\SearchException;
use App\Exceptions\WebFetchException;
use App\Services\Search\SearchService;
use App\Services\WebFetch\WebFetchService;

class WebToolHandler
{
    public function __construct(
        protected SearchService $searchService,
        protected WebFetchService $webFetchService
    ) {}

    /**
     * Execute a web tool by name.
     *
     * @param  array<string, mixed>  $args
     */
    public function execute(string $toolName, array $args): ToolResult
    {
        return match ($toolName) {
            'web_search' => $this->search($args),
            'web_fetch' => $this->fetch($args),
            default => new ToolResult(
                success: false,
                output: '',
                error: "Unknown web tool: {$toolName}"
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function search(array $args): ToolResult
    {
        $query = new SearchQuery(
            query: $args['query'] ?? '',
            maxResults: $args['max_results'] ?? 5,
        );

        if (! $query->isValid()) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Search query cannot be empty'
            );
        }

        try {
            $results = $this->searchService->search($query);

            if ($results->isEmpty()) {
                return new ToolResult(
                    success: true,
                    output: 'No results found for: '.$query->query,
                    error: null
                );
            }

            return new ToolResult(
                success: true,
                output: $results->toJson(),
                error: null
            );
        } catch (SearchException $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Web search failed: '.$e->getMessage()
            );
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function fetch(array $args): ToolResult
    {
        $request = new FetchRequest(url: $args['url'] ?? '');

        if (! $request->isValid()) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Invalid or missing URL'
            );
        }

        try {
            $document = $this->webFetchService->fetch($request);

            return new ToolResult(
                success: true,
                output: $document->toJson(),
                error: null
            );
        } catch (WebFetchException $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Web fetch failed: '.$e->getMessage()
            );
        }
    }
}
