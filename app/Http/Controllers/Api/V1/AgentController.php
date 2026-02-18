<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Agent Management
 *
 * APIs for managing AI agents
 *
 * @authenticated
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
     * @apiResourceCollection App\Http\Resources\AgentResource
     *
     * @apiResourceModel App\Models\Agent with=systemPrompts paginate=15
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $agents = $request->user()
            ->agents()
            ->with(['systemPrompts'])
            ->paginate($request->input('per_page', 15));

        return AgentResource::collection($agents);
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
     *
     * @apiResource App\Http\Resources\AgentResource
     *
     * @apiResourceModel App\Models\Agent with=systemPrompts
     *
     * @apiResourceAdditional status=201
     *
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"name": ["The name field is required."]}}
     */
    public function store(StoreAgentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        $agent = $this->agentService->create($data);

        return (new AgentResource($agent))->response()->setStatusCode(201);
    }

    /**
     * Show Agent
     *
     * Get details of a specific agent.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @apiResource App\Http\Resources\AgentResource
     *
     * @apiResourceModel App\Models\Agent with=systemPrompts
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Agent] 1"}
     */
    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $agent->load(['systemPrompts']);

        return (new AgentResource($agent))->response();
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
     *
     * @apiResource App\Http\Resources\AgentResource
     *
     * @apiResourceModel App\Models\Agent with=systemPrompts
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Agent] 1"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"name": ["The name must be a string."]}}
     */
    public function update(UpdateAgentRequest $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        $agent = $this->agentService->update($agent, $request->validated());

        return (new AgentResource($agent))->response();
    }

    /**
     * Delete Agent
     *
     * Delete an agent permanently.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @response 204 scenario="Success"
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Agent] 1"}
     */
    public function destroy(Agent $agent): JsonResponse
    {
        $this->authorize('delete', $agent);

        $this->agentService->delete($agent);

        return response()->json(null, 204);
    }
}
