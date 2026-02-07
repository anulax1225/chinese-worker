<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Tool;
use App\Services\AIBackendManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        protected AIBackendManager $backendManager
    ) {}

    /**
     * Show the dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Agent stats in single query
        $agentStats = Agent::where('user_id', $user->id)
            ->selectRaw("
                count(*) as total,
                sum(case when status = 'active' then 1 else 0 end) as active,
                sum(case when status = 'inactive' then 1 else 0 end) as inactive,
                sum(case when status = 'error' then 1 else 0 end) as error
            ")
            ->first();

        // Conversation stats in single query
        $conversationStats = Conversation::where('user_id', $user->id)
            ->selectRaw("
                count(*) as total,
                sum(case when status = 'completed' then 1 else 0 end) as completed,
                sum(case when status = 'failed' then 1 else 0 end) as failed,
                sum(case when status = 'active' then 1 else 0 end) as active,
                sum(case when status = 'cancelled' then 1 else 0 end) as cancelled,
                sum(case when status = 'waiting_tool' then 1 else 0 end) as waiting_tool,
                sum(case when date(started_at) = curdate() then 1 else 0 end) as today,
                sum(case when date(started_at) = date_sub(curdate(), interval 1 day) then 1 else 0 end) as yesterday
            ")
            ->first();

        $totalTools = Tool::where('user_id', $user->id)->count();

        // Top agents by conversation count
        $topAgents = Agent::where('user_id', $user->id)
            ->withCount('conversations')
            ->orderByDesc('conversations_count')
            ->limit(5)
            ->get(['id', 'name', 'status', 'ai_backend']);

        // Recent conversations (increased to 6)
        $recentConversations = Conversation::where('user_id', $user->id)
            ->with(['agent:id,name'])
            ->latest('last_activity_at')
            ->limit(6)
            ->get();

        $total = (int) ($conversationStats->total ?? 0);
        $completed = (int) ($conversationStats->completed ?? 0);

        return Inertia::render('Dashboard', [
            'stats' => [
                'agents' => [
                    'total' => (int) ($agentStats->total ?? 0),
                    'active' => (int) ($agentStats->active ?? 0),
                    'inactive' => (int) ($agentStats->inactive ?? 0),
                    'error' => (int) ($agentStats->error ?? 0),
                ],
                'conversations' => [
                    'total' => $total,
                    'completed' => $completed,
                    'failed' => (int) ($conversationStats->failed ?? 0),
                    'active' => (int) ($conversationStats->active ?? 0),
                    'cancelled' => (int) ($conversationStats->cancelled ?? 0),
                    'waitingTool' => (int) ($conversationStats->waiting_tool ?? 0),
                    'today' => (int) ($conversationStats->today ?? 0),
                    'yesterday' => (int) ($conversationStats->yesterday ?? 0),
                ],
                'tools' => $totalTools,
                'successRate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            ],
            'topAgents' => $topAgents,
            'recentConversations' => $recentConversations,
            'backends' => Inertia::defer(fn () => $this->getBackendsStatus()),
        ]);
    }

    /**
     * @return array<int, array{name: string, driver: string, is_default: bool, status: string}>
     */
    private function getBackendsStatus(): array
    {
        $defaultBackend = config('ai.default');

        return collect(config('ai.backends', []))->map(function ($config, $name) use ($defaultBackend) {
            $status = 'unknown';
            try {
                $this->backendManager->driver($name);
                $status = 'connected';
            } catch (Throwable) {
                $status = 'error';
            }

            return [
                'name' => $name,
                'driver' => $config['driver'],
                'is_default' => $name === $defaultBackend,
                'status' => $status,
            ];
        })->values()->all();
    }
}
