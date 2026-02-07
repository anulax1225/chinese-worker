<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\SystemPrompt;
use App\Models\Tool;
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
        $systemPrompts = SystemPrompt::where('is_active', true)->orderBy('name')->get();
        $backends = config('ai.backends');
        $defaultBackend = config('ai.default');

        return Inertia::render('Agents/Create', [
            'tools' => $tools,
            'systemPrompts' => $systemPrompts,
            'backends' => array_keys($backends),
            'defaultBackend' => $defaultBackend,
            'breadcrumbs' => [
                ['label' => 'Agents', 'href' => '/agents'],
                ['label' => 'Create'],
            ],
        ]);
    }

    /**
     * Display the specified agent.
     */
    public function show(Request $request, Agent $agent): Response
    {
        $this->authorize('view', $agent);

        $agent->load(['tools', 'systemPrompts']);

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

        $agent->load(['tools', 'systemPrompts']);
        $tools = Tool::where('user_id', $request->user()->id)->get();
        $systemPrompts = SystemPrompt::where('is_active', true)->orderBy('name')->get();
        $backends = config('ai.backends');

        return Inertia::render('Agents/Edit', [
            'agent' => $agent,
            'tools' => $tools,
            'systemPrompts' => $systemPrompts,
            'backends' => array_keys($backends),
            'breadcrumbs' => [
                ['label' => 'Agents', 'href' => '/agents'],
                ['label' => $agent->name, 'href' => "/agents/{$agent->id}"],
                ['label' => 'Edit'],
            ],
        ]);
    }
}
