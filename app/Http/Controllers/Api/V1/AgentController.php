<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StreamsServerSentEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentGenerateRequest;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Services\AgentService;
use App\Services\AIBackendManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Agent Management
 *
 * APIs for managing AI agents
 *
 * @authenticated
 */
class AgentController extends Controller
{
    use StreamsServerSentEvents;

    public function __construct(
        protected AgentService $agentService,
        protected AIBackendManager $backendManager,
    ) {}

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

    /**
     * Generate Text
     *
     * Generate text completion using the agent's configured AI backend.
     * This is a simple text generation endpoint (not chat-based).
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam prompt string required The prompt to generate from. Example: Why is the sky blue?
     * @bodyParam stream boolean Return a streaming response. Example: false
     * @bodyParam suffix string Text after prompt for fill-in-the-middle. Example: The end.
     * @bodyParam images array Base64-encoded images for vision models.
     * @bodyParam format string|object Structured output format ('json' or JSON schema).
     * @bodyParam system string System prompt. Example: You are a helpful assistant.
     * @bodyParam think boolean|string Enable thinking mode (true, false, 'high', 'medium', 'low'). Example: true
     * @bodyParam raw boolean Skip prompt templating. Example: false
     * @bodyParam max_tokens integer Maximum tokens to generate. Example: 1000
     * @bodyParam temperature number Temperature (0-2). Example: 0.7
     * @bodyParam top_p number Top-p sampling (0-1). Example: 0.9
     * @bodyParam top_k integer Top-k sampling. Example: 40
     * @bodyParam seed integer Random seed for reproducibility. Example: 42
     * @bodyParam stop array|string Stop sequences.
     *
     * @response 200 {"content": "The sky appears blue because...", "model": "llama3.1", "done": true, "done_reason": "stop", "tokens_used": 45, "tokens_per_second": 25.5}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"prompt": ["The prompt field is required."]}}
     */
    public function generate(AgentGenerateRequest $request, Agent $agent): JsonResponse|StreamedResponse
    {
        $this->authorize('view', $agent);

        $generateRequest = $request->toGenerateRequest();
        $backend = $this->backendManager->forAgent($agent)['backend'];

        if ($request->wantsStream()) {
            return $this->streamGenerate($backend, $generateRequest);
        }

        $response = $backend->generate($generateRequest);

        return response()->json($response->toArray());
    }

    /**
     * Stream the generate response as SSE events.
     */
    protected function streamGenerate(
        \App\Contracts\AIBackendInterface $backend,
        \App\DTOs\GenerateRequest $request
    ): StreamedResponse {
        return response()->stream(
            function () use ($backend, $request): \Generator {
                yield $this->formatSSEEvent('connected', ['status' => 'connected']);

                try {
                    $response = $backend->streamGenerate($request, function (string $chunk, string $type): void {
                        echo $this->formatSSEEvent($type, ['chunk' => $chunk]);

                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    });

                    yield $this->formatSSEEvent('completed', $response->toArray());
                } catch (\Exception $e) {
                    yield $this->formatSSEEvent('error', [
                        'message' => $e->getMessage(),
                    ]);
                }
            },
            200,
            $this->getSSEHeaders()
        );
    }
}
