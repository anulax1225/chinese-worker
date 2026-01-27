<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Execution;
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

        $totalExecutions = Execution::whereHas('task.agent', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->count();

        $completedExecutions = Execution::whereHas('task.agent', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->where('status', 'completed')->count();

        $successRate = $totalExecutions > 0
            ? round(($completedExecutions / $totalExecutions) * 100, 1)
            : 0;

        // Get recent executions
        $recentExecutions = Execution::whereHas('task.agent', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->with(['task.agent'])
            ->latest()
            ->take(5)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalAgents' => $totalAgents,
                'activeAgents' => $activeAgents,
                'totalTools' => $totalTools,
                'totalExecutions' => $totalExecutions,
                'successRate' => $successRate,
            ],
            'recentExecutions' => $recentExecutions,
        ]);
    }
}
