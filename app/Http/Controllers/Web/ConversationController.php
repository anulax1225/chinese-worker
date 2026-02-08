<?php

namespace App\Http\Controllers\Web;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Document;
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
     * Display the specified conversation (chat interface).
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        $this->authorize('view', $conversation);

        $conversation->load(['agent:id,name,description,ai_backend']);

        // Get user's ready documents for attachment
        $documents = Document::where('user_id', $request->user()->id)
            ->where('status', DocumentStatus::Ready)
            ->latest()
            ->limit(50)
            ->get(['id', 'title', 'status', 'mime_type', 'file_size']);

        return Inertia::render('Conversations/Show', [
            'conversation' => $conversation,
            'documents' => $documents,
            'breadcrumbs' => [
                ['label' => 'Conversations', 'href' => '/conversations'],
                ['label' => "#{$conversation->id}"],
            ],
        ]);
    }
}
