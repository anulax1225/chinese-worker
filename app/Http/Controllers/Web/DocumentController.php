<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    /**
     * Display a listing of documents.
     */
    public function index(Request $request): Response
    {
        $query = Document::where('user_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('title', 'like', "%{$search}%");
        }

        $documents = $query->latest()->cursorPaginate(12)->withQueryString();

        return Inertia::render('Documents/Index', [
            'documents' => Inertia::merge(fn () => $documents->items()),
            'nextCursor' => $documents->nextCursor()?->encode(),
            'filters' => [
                'status' => $request->input('status'),
                'search' => $request->input('search'),
            ],
            'breadcrumbs' => [
                ['label' => 'Documents'],
            ],
        ]);
    }

    /**
     * Show the form for creating a new document.
     */
    public function create(Request $request): Response
    {
        $supportedTypes = config('document.supported_types', [
            'text/plain',
            'text/markdown',
            'text/csv',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/html',
            'application/json',
        ]);

        return Inertia::render('Documents/Create', [
            'supportedTypes' => $supportedTypes,
            'breadcrumbs' => [
                ['label' => 'Documents', 'href' => '/documents'],
                ['label' => 'Create'],
            ],
        ]);
    }

    /**
     * Display the specified document.
     */
    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $document->load(['stages', 'file']);

        return Inertia::render('Documents/Show', [
            'document' => $document,
            'chunksCount' => $document->chunks()->count(),
            'totalTokens' => $document->chunks()->sum('token_count'),
            'breadcrumbs' => [
                ['label' => 'Documents', 'href' => '/documents'],
                ['label' => $document->title ?? 'Untitled'],
            ],
        ]);
    }
}
