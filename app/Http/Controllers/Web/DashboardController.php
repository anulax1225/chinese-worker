<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Tool;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Get stats
        $totalAgents = Agent::where('user_id', $user->id)->count();
        $activeAgents = Agent::where('user_id', $user->id)->where('status', 'active')->count();
        $totalTools = Tool::where('user_id', $user->id)->count();

        $totalConversations = Conversation::where('user_id', $user->id)->count();

        $completedConversations = Conversation::where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $successRate = $totalConversations > 0
            ? round(($completedConversations / $totalConversations) * 100, 1)
            : 0;

        // Get recent conversations
        $recentConversations = Conversation::where('user_id', $user->id)
            ->with(['agent'])
            ->latest()
            ->take(5)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalAgents' => $totalAgents,
                'activeAgents' => $activeAgents,
                'totalTools' => $totalTools,
                'totalConversations' => $totalConversations,
                'successRate' => $successRate,
            ],
            'recentConversations' => $recentConversations,
        ]);
    }
}
