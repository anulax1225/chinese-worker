<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'token' => fn () => $request->session()->get('token'),
            ],
            'sidebarConversations' => fn () => $this->getSidebarConversations($request),
            'agents' => fn () => $this->getActiveAgents($request),
        ];
    }

    /**
     * Get the 4 most recent conversations for the sidebar.
     *
     * @return array<int, array{id: int, title: string, agent_name: string|null, status: string}>
     */
    protected function getSidebarConversations(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return Conversation::query()
            ->where('user_id', $request->user()->id)
            ->with('agent:id,name')
            ->orderByDesc('last_activity_at')
            ->limit(4)
            ->get(['id', 'agent_id', 'status', 'last_activity_at'])
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->agent?->name ?? 'Conversation #'.$conversation->id,
                'agent_name' => $conversation->agent?->name,
                'status' => $conversation->status,
            ])
            ->toArray();
    }

    /**
     * Get active agents for the authenticated user.
     *
     * @return array<int, array{id: int, name: string, description: string|null}>
     */
    protected function getActiveAgents(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return Agent::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->toArray();
    }
}
