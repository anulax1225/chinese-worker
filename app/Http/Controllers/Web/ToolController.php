<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    /**
     * Display a listing of tools.
     */
    public function index(Request $request): Response
    {
        $query = Tool::where('user_id', $request->user()->id)
            ->withCount('agents');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $tools = $query->latest()->cursorPaginate(12)->withQueryString();

        return Inertia::render('Tools/Index', [
            'tools' => Inertia::merge(fn () => $tools->items()),
            'nextCursor' => $tools->nextCursor()?->encode(),
            'filters' => [
                'type' => $request->input('type'),
                'search' => $request->input('search'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new tool.
     */
    public function create(): Response
    {
        return Inertia::render('Tools/Create');
    }

    /**
     * Display the specified tool.
     */
    public function show(Request $request, Tool $tool): Response
    {
        $this->authorize('view', $tool);

        $tool->load('agents');

        return Inertia::render('Tools/Show', [
            'tool' => $tool,
        ]);
    }

    /**
     * Show the form for editing the specified tool.
     */
    public function edit(Request $request, Tool $tool): Response
    {
        $this->authorize('update', $tool);

        return Inertia::render('Tools/Edit', [
            'tool' => $tool,
        ]);
    }
}
