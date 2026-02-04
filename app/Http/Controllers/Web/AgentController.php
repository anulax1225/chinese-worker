<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Tool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    /**
     * Display a listing of agents.
     */
    public function index(Request $request): Response
    {
        $query = Agent::where('user_id', $request->user()->id)
            ->withCount(['tools']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $agents = $query->latest()->cursorPaginate(12)->withQueryString();

        return Inertia::render('Agents/Index', [
            'agents' => Inertia::merge(fn () => $agents->items()),
            'nextCursor' => $agents->nextCursor()?->encode(),
            'filters' => [
                'status' => $request->input('status'),
                'search' => $request->input('search'),
            ],
            'breadcrumbs' => [
                ['label' => 'Agents'],
            ],
        ]);
    }

    /**
     * Show the form for creating a new agent.
     */
    public function create(Request $request): Response
    {
        $tools = Tool::where('user_id', $request->user()->id)->get();
        $backends = config('ai.backends');
        $defaultBackend = config('ai.default');

        return Inertia::render('Agents/Create', [
            'tools' => $tools,
            'backends' => array_keys($backends),
            'defaultBackend' => $defaultBackend,
            'breadcrumbs' => [
                ['label' => 'Agents', 'href' => '/agents'],
                ['label' => 'Create'],
            ],
        ]);
    }

    /**
     * Store a newly created agent.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'code' => ['required', 'string'],
            'config' => ['nullable', 'array'],
            'status' => ['required', 'in:active,inactive,error'],
            'ai_backend' => ['required', 'string'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['exists:tools,id'],
        ]);

        $agent = Agent::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'code' => $validated['code'],
            'config' => $validated['config'] ?? [],
            'status' => $validated['status'],
            'ai_backend' => $validated['ai_backend'],
        ]);

        if (! empty($validated['tool_ids'])) {
            $agent->tools()->attach($validated['tool_ids']);
        }

        return redirect()->route('agents.show', $agent)
            ->with('success', 'Agent created successfully.');
    }

    /**
     * Display the specified agent.
     */
    public function show(Request $request, Agent $agent): Response
    {
        $this->authorize('view', $agent);

        $agent->load(['tools']);

        return Inertia::render('Agents/Show', [
            'agent' => $agent,
            'breadcrumbs' => [
                ['label' => 'Agents', 'href' => '/agents'],
                ['label' => $agent->name],
            ],
        ]);
    }

    /**
     * Show the form for editing the specified agent.
     */
    public function edit(Request $request, Agent $agent): Response
    {
        $this->authorize('update', $agent);

        $agent->load('tools');
        $tools = Tool::where('user_id', $request->user()->id)->get();
        $backends = config('ai.backends');

        return Inertia::render('Agents/Edit', [
            'agent' => $agent,
            'tools' => $tools,
            'backends' => array_keys($backends),
            'breadcrumbs' => [
                ['label' => 'Agents', 'href' => '/agents'],
                ['label' => $agent->name, 'href' => "/agents/{$agent->id}"],
                ['label' => 'Edit'],
            ],
        ]);
    }

    /**
     * Update the specified agent.
     */
    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'code' => ['required', 'string'],
            'config' => ['nullable', 'array'],
            'status' => ['required', 'in:active,inactive,error'],
            'ai_backend' => ['required', 'string'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['exists:tools,id'],
        ]);

        $agent->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'code' => $validated['code'],
            'config' => $validated['config'] ?? [],
            'status' => $validated['status'],
            'ai_backend' => $validated['ai_backend'],
        ]);

        $agent->tools()->sync($validated['tool_ids'] ?? []);

        return redirect()->route('agents.show', $agent)
            ->with('success', 'Agent updated successfully.');
    }

    /**
     * Remove the specified agent.
     */
    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('delete', $agent);

        $agent->delete();

        return redirect()->route('agents.index')
            ->with('success', 'Agent deleted successfully.');
    }
}
