<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutionController extends Controller
{
    /**
     * Display a listing of executions.
     */
    public function index(Request $request): Response
    {
        $query = Execution::whereHas('task.agent', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })->with(['task.agent']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('agent_id')) {
            $query->whereHas('task', function ($q) use ($request) {
                $q->where('agent_id', $request->input('agent_id'));
            });
        }

        $executions = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('Executions/Index', [
            'executions' => $executions,
            'filters' => [
                'status' => $request->input('status'),
                'agent_id' => $request->input('agent_id'),
            ],
        ]);
    }

    /**
     * Display the specified execution.
     */
    public function show(Request $request, Execution $execution): Response
    {
        // Authorization: ensure the execution belongs to the user
        $execution->load(['task.agent']);

        if ($execution->task->agent->user_id !== $request->user()->id) {
            abort(403);
        }

        return Inertia::render('Executions/Show', [
            'id' => $execution->id,
        ]);
    }
}
