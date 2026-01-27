<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Http\RedirectResponse;
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

        $tools = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('Tools/Index', [
            'tools' => $tools,
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
     * Store a newly created tool.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:api,function,command'],
            'config' => ['required', 'array'],
        ]);

        $tool = Tool::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'config' => $validated['config'],
        ]);

        return redirect()->route('tools.show', $tool)
            ->with('success', 'Tool created successfully.');
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

    /**
     * Update the specified tool.
     */
    public function update(Request $request, Tool $tool): RedirectResponse
    {
        $this->authorize('update', $tool);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:api,function,command'],
            'config' => ['required', 'array'],
        ]);

        $tool->update($validated);

        return redirect()->route('tools.show', $tool)
            ->with('success', 'Tool updated successfully.');
    }

    /**
     * Remove the specified tool.
     */
    public function destroy(Request $request, Tool $tool): RedirectResponse
    {
        $this->authorize('delete', $tool);

        $tool->delete();

        return redirect()->route('tools.index')
            ->with('success', 'Tool deleted successfully.');
    }
}
