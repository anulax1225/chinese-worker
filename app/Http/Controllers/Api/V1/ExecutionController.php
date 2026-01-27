<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteAgentRequest;
use App\Models\Agent;
use App\Models\Execution;
use App\Services\ExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Execution Management
 *
 * APIs for executing agents and monitoring executions
 */
class ExecutionController extends Controller
{
    public function __construct(protected ExecutionService $executionService) {}

    /**
     * List Executions
     *
     * Get a paginated list of all executions for the authenticated user.
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     * @queryParam status string Filter by execution status. Example: completed
     *
     * @response 200 {"data": [{"id": 1, "task_id": 1, "status": "completed", "started_at": "2026-01-26T10:00:00.000000Z", "completed_at": "2026-01-26T10:05:00.000000Z", "result": {"output": "Success"}, "logs": "Execution logs...", "error": null, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:05:00.000000Z"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $query = Execution::query()
            ->whereHas('task.agent', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            });

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $executions = $query
            ->with(['task.agent'])
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($executions);
    }

    /**
     * Execute Agent
     *
     * Execute an agent with the given payload.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam payload object required The execution payload/input data. Example: {"task": "Generate code", "context": "User authentication"}
     * @bodyParam file_ids array List of file IDs to use as input. Example: [1, 2]
     * @bodyParam priority integer Execution priority (0-10). Example: 5
     * @bodyParam scheduled_at string Schedule execution for future time. Example: 2026-01-27T10:00:00Z
     *
     * @response 201 {"id": 1, "task_id": 1, "status": "pending", "started_at": null, "completed_at": null, "result": null, "logs": null, "error": null, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z", "files": []}
     */
    public function execute(ExecuteAgentRequest $request, Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $execution = $this->executionService->execute(
            $agent,
            $request->validated(),
            $request->input('file_ids', [])
        );

        return response()->json($execution, 201);
    }

    /**
     * Show Execution
     *
     * Get details of a specific execution.
     *
     * @urlParam execution integer required The execution ID. Example: 1
     *
     * @response 200 {"id": 1, "task_id": 1, "status": "completed", "started_at": "2026-01-26T10:00:00.000000Z", "completed_at": "2026-01-26T10:05:00.000000Z", "result": {"output": "Success"}, "logs": "Execution logs...", "error": null, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:05:00.000000Z", "task": {}, "files": []}
     */
    public function show(Execution $execution): JsonResponse
    {
        $this->authorize('view', $execution->task->agent);

        $execution->load(['task.agent', 'files']);

        return response()->json($execution);
    }

    /**
     * Get Execution Logs
     *
     * Get the logs for a specific execution.
     *
     * @urlParam execution integer required The execution ID. Example: 1
     *
     * @response 200 {"logs": "Execution started at 2026-01-26 10:00:00\nProcessing...\nExecution completed successfully"}
     */
    public function logs(Execution $execution): JsonResponse
    {
        $this->authorize('view', $execution->task->agent);

        return response()->json([
            'logs' => $this->executionService->getLogs($execution),
        ]);
    }

    /**
     * Get Execution Outputs
     *
     * Get the output files for a specific execution.
     *
     * @urlParam execution integer required The execution ID. Example: 1
     *
     * @response 200 {"outputs": [{"id": 3, "user_id": 1, "path": "files/output/result.txt", "type": "output", "size": 512, "mime_type": "text/plain", "created_at": "2026-01-26T10:05:00.000000Z", "updated_at": "2026-01-26T10:05:00.000000Z"}]}
     */
    public function outputs(Execution $execution): JsonResponse
    {
        $this->authorize('view', $execution->task->agent);

        return response()->json([
            'outputs' => $this->executionService->getOutputs($execution),
        ]);
    }

    /**
     * Stream Agent Execution
     *
     * Execute an agent with real-time streaming response using Server-Sent Events (SSE).
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam payload object required The execution payload/input data. Example: {"task": "Generate code", "context": "User authentication"}
     * @bodyParam file_ids array List of file IDs to use as input. Example: [1, 2]
     *
     * @response 200 stream:data: {"type": "chunk", "content": "Hello"}\ndata: {"type": "chunk", "content": " World"}\ndata: {"type": "done", "execution_id": 1}\n
     */
    public function stream(ExecuteAgentRequest $request, Agent $agent)
    {
        $this->authorize('view', $agent);

        return response()->stream(function () use ($request, $agent) {
            $execution = $this->executionService->streamExecute(
                $agent,
                $request->validated(),
                $request->input('file_ids', []),
                function (string $chunk) {
                    echo 'data: '.json_encode([
                        'type' => 'chunk',
                        'content' => $chunk,
                    ])."\n\n";
                    ob_flush();
                    flush();
                }
            );

            // Send completion event
            echo 'data: '.json_encode([
                'type' => 'done',
                'execution_id' => $execution->id,
                'status' => $execution->status,
            ])."\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
