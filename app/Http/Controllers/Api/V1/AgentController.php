<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Models\Agent;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Agent Management
 *
 * APIs for managing AI agents
 */
class AgentController extends Controller
{
    public function __construct(protected AgentService $agentService) {}

    /**
     * List Agents
     *
     * Get a paginated list of all agents for the authenticated user.
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 1,
     *       "name": "Code Assistant",
     *       "description": "Helps with coding tasks",
     *       "code": "// Agent code here",
     *       "config": {"temperature": 0.7},
     *       "status": "active",
     *       "ai_backend": "ollama",
     *       "created_at": "2026-01-26T10:00:00.000000Z",
     *       "updated_at": "2026-01-26T10:00:00.000000Z"
     *     }
     *   ],
     *   "links": {},
     *   "meta": {}
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $agents = $request->user()
            ->agents()
            ->with('tools')
            ->paginate($request->input('per_page', 15));

        return response()->json($agents);
    }

    /**
     * Create Agent
     *
     * Create a new AI agent.
     *
     * @bodyParam name string required The agent's name. Example: Code Assistant
     * @bodyParam description string The agent's description. Example: Helps with coding tasks
     * @bodyParam code string required The agent's code/instructions. Example: // Agent code here
     * @bodyParam config object The agent's configuration. Example: {"temperature": 0.7}
     * @bodyParam status string The agent's status. Must be one of: active, inactive, error. Example: active
     * @bodyParam ai_backend string The AI backend to use. Must be one of: ollama, anthropic, openai. Example: ollama
     * @bodyParam tool_ids array List of tool IDs to attach to the agent. Example: [1, 2, 3]
     *
     * @response 201 {
     *   "id": 1,
     *   "user_id": 1,
     *   "name": "Code Assistant",
     *   "description": "Helps with coding tasks",
     *   "code": "// Agent code here",
     *   "config": {"temperature": 0.7},
     *   "status": "active",
     *   "ai_backend": "ollama",
     *   "created_at": "2026-01-26T10:00:00.000000Z",
     *   "updated_at": "2026-01-26T10:00:00.000000Z",
     *   "tools": []
     * }
     */
    public function store(StoreAgentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        $agent = $this->agentService->create($data);

        return response()->json($agent, 201);
    }

    /**
     * Show Agent
     *
     * Get details of a specific agent.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @response 200 {
     *   "id": 1,
     *   "user_id": 1,
     *   "name": "Code Assistant",
     *   "description": "Helps with coding tasks",
     *   "code": "// Agent code here",
     *   "config": {"temperature": 0.7},
     *   "status": "active",
     *   "ai_backend": "ollama",
     *   "created_at": "2026-01-26T10:00:00.000000Z",
     *   "updated_at": "2026-01-26T10:00:00.000000Z",
     *   "tools": [],
     *   "tasks": []
     * }
     */
    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $agent->load(['tools', 'tasks']);

        return response()->json($agent);
    }

    /**
     * Update Agent
     *
     * Update an existing agent.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam name string The agent's name. Example: Updated Code Assistant
     * @bodyParam description string The agent's description. Example: Updated description
     * @bodyParam code string The agent's code/instructions. Example: // Updated agent code
     * @bodyParam config object The agent's configuration. Example: {"temperature": 0.8}
     * @bodyParam status string The agent's status. Must be one of: active, inactive, error. Example: inactive
     * @bodyParam ai_backend string The AI backend to use. Must be one of: ollama, anthropic, openai. Example: anthropic
     * @bodyParam tool_ids array List of tool IDs to sync with the agent. Example: [1, 3]
     *
     * @response 200 {
     *   "id": 1,
     *   "user_id": 1,
     *   "name": "Updated Code Assistant",
     *   "description": "Updated description",
     *   "code": "// Updated agent code",
     *   "config": {"temperature": 0.8},
     *   "status": "inactive",
     *   "ai_backend": "anthropic",
     *   "created_at": "2026-01-26T10:00:00.000000Z",
     *   "updated_at": "2026-01-26T11:00:00.000000Z",
     *   "tools": []
     * }
     */
    public function update(UpdateAgentRequest $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        $agent = $this->agentService->update($agent, $request->validated());

        return response()->json($agent);
    }

    /**
     * Delete Agent
     *
     * Delete an agent permanently.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @response 204
     */
    public function destroy(Agent $agent): JsonResponse
    {
        $this->authorize('delete', $agent);

        $this->agentService->delete($agent);

        return response()->json(null, 204);
    }

    /**
     * Attach Tools
     *
     * Attach tools to an agent.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam tool_ids array required List of tool IDs to attach. Example: [4, 5]
     *
     * @response 200 {
     *   "message": "Tools attached successfully",
     *   "agent": {
     *     "id": 1,
     *     "name": "Code Assistant",
     *     "tools": []
     *   }
     * }
     */
    public function attachTools(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        $request->validate([
            'tool_ids' => ['required', 'array'],
            'tool_ids.*' => ['integer', 'exists:tools,id'],
        ]);

        $this->agentService->attachTools($agent, $request->input('tool_ids'));

        return response()->json([
            'message' => 'Tools attached successfully',
            'agent' => $agent->fresh(['tools']),
        ]);
    }

    /**
     * Detach Tool
     *
     * Detach a specific tool from an agent.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     * @urlParam tool integer required The tool ID. Example: 2
     *
     * @response 200 {
     *   "message": "Tool detached successfully"
     * }
     */
    public function detachTool(Agent $agent, int $toolId): JsonResponse
    {
        $this->authorize('update', $agent);

        $this->agentService->detachTools($agent, [$toolId]);

        return response()->json(['message' => 'Tool detached successfully']);
    }
}
