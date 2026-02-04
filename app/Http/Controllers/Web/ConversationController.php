<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /**
     * Display a listing of conversations.
     */
    public function index(Request $request): Response
    {
        $query = Conversation::where('user_id', $request->user()->id)
            ->with(['agent:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('agent', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $conversations = $query->latest('last_activity_at')
            ->cursorPaginate(12)
            ->withQueryString();

        $agents = Agent::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->get(['id', 'name']);

        return Inertia::render('Conversations/Index', [
            'conversations' => Inertia::merge(fn () => $conversations->items()),
            'nextCursor' => $conversations->nextCursor()?->encode(),
            'agents' => $agents,
            'filters' => [
                'status' => $request->input('status'),
                'agent_id' => $request->input('agent_id'),
                'search' => $request->input('search'),
            ],
            'breadcrumbs' => [
                ['label' => 'Conversations'],
            ],
        ]);
    }

    /**
     * Show the form for creating a new conversation.
     */
    public function create(Request $request): Response
    {
        $agents = Agent::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->get(['id', 'name', 'description']);

        return Inertia::render('Conversations/Create', [
            'agents' => $agents,
            'breadcrumbs' => [
                ['label' => 'Conversations', 'href' => '/conversations'],
                ['label' => 'New'],
            ],
        ]);
    }

    /**
     * Store a newly created conversation.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'exists:agents,id'],
            'message' => ['nullable', 'string', 'max:10000'],
        ]);

        $agent = Agent::findOrFail($validated['agent_id']);
        $this->authorize('view', $agent);

        $messages = [];
        if (! empty($validated['message'])) {
            $messages[] = [
                'role' => 'user',
                'content' => $validated['message'],
            ];
        }

        $conversation = Conversation::create([
            'user_id' => $request->user()->id,
            'agent_id' => $validated['agent_id'],
            'status' => 'active',
            'messages' => $messages,
            'metadata' => [],
            'turn_count' => 0,
            'total_tokens' => 0,
            'started_at' => now(),
            'last_activity_at' => now(),
            'client_type' => 'cli_web',
        ]);

        return redirect()->route('conversations.show', $conversation);
    }

    /**
     * Display the specified conversation (chat interface).
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        $this->authorize('view', $conversation);

        $conversation->load(['agent:id,name,description,ai_backend']);

        return Inertia::render('Conversations/Show', [
            'conversation' => $conversation,
            'breadcrumbs' => [
                ['label' => 'Conversations', 'href' => '/conversations'],
                ['label' => "#{$conversation->id}"],
            ],
        ]);
    }

    /**
     * Remove the specified conversation.
     */
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return redirect()->route('conversations.index')
            ->with('success', 'Conversation deleted successfully.');
    }
}
