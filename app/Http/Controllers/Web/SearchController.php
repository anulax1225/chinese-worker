<?php

namespace App\Http\Controllers\Web;

use App\DTOs\Search\SearchQuery;
use App\Exceptions\SearchException;
use App\Http\Controllers\Controller;
use App\Services\Search\SearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService
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
}
