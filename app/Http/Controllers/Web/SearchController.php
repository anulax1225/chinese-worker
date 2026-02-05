<?php

namespace App\Http\Controllers\Web;

use App\DTOs\Search\SearchQuery;
use App\DTOs\WebFetch\FetchRequest;
use App\Exceptions\SearchException;
use App\Exceptions\WebFetchException;
use App\Http\Controllers\Controller;
use App\Services\Search\SearchService;
use App\Services\WebFetch\WebFetchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly WebFetchService $webFetchService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Search/Index', [
            'query' => '',
            'maxResults' => 10,
            'engines' => '',
            'results' => null,
            'error' => null,
            'searchTime' => null,
            'fromCache' => false,
            'serviceAvailable' => $this->searchService->isAvailable(),
        ]);
    }

    public function search(Request $request): Response
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:50'],
            'engines' => ['nullable', 'string'],
        ]);

        $results = null;
        $error = null;
        $searchTime = null;
        $fromCache = false;

        $query = new SearchQuery(
            query: $validated['query'],
            maxResults: (int) ($validated['max_results'] ?? 10),
            engines: ! empty($validated['engines'])
                ? array_map('trim', explode(',', $validated['engines']))
                : null,
        );

        try {
            $collection = $this->searchService->search($query);
            $results = $collection->toArray();
            $searchTime = $collection->searchTime;
            $fromCache = $collection->fromCache;
        } catch (SearchException $e) {
            $error = $e->getMessage();
        }

        return Inertia::render('Search/Index', [
            'query' => $validated['query'],
            'maxResults' => (int) ($validated['max_results'] ?? 10),
            'engines' => $validated['engines'] ?? '',
            'results' => $results,
            'error' => $error,
            'searchTime' => $searchTime,
            'fromCache' => $fromCache,
            'serviceAvailable' => $this->searchService->isAvailable(),
        ]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url'],
        ]);

        try {
            $fetchRequest = new FetchRequest(url: $validated['url']);

            if (! $fetchRequest->isValid()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid URL',
                ], 422);
            }

            $document = $this->webFetchService->fetch($fetchRequest);

            return response()->json([
                'success' => true,
                'data' => $document->toArray(),
            ]);
        } catch (WebFetchException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
