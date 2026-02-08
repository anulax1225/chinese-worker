<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSystemPromptRequest;
use App\Http\Requests\UpdateSystemPromptRequest;
use App\Http\Resources\SystemPromptResource;
use App\Models\SystemPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group System Prompt Management
 *
 * APIs for managing system prompts.
 *
 * @authenticated
 */
class SystemPromptController extends Controller
{
    /**
     * List System Prompts
     *
     * Get a paginated list of all system prompts.
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     * @queryParam search string Search by name. Example: greeting
     * @queryParam active boolean Filter by active status. Example: true
     *
     * @apiResourceCollection App\Http\Resources\SystemPromptResource
     *
     * @apiResourceModel App\Models\SystemPrompt paginate=15
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SystemPrompt::class);

        $prompts = SystemPrompt::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return SystemPromptResource::collection($prompts);
    }

    /**
     * Create System Prompt
     *
     * Create a new system prompt.
     *
     * @bodyParam name string required The prompt's name. Example: Greeting Prompt
     * @bodyParam slug string required Unique slug identifier. Example: greeting-prompt
     * @bodyParam template string required The Blade template content. Example: Hello {{ $name }}!
     * @bodyParam required_variables array List of required variable names. Example: ["name"]
     * @bodyParam default_values object Default values for variables. Example: {"name": "World"}
     * @bodyParam is_active boolean Whether the prompt is active. Example: true
     *
     * @apiResource App\Http\Resources\SystemPromptResource
     *
     * @apiResourceModel App\Models\SystemPrompt
     *
     * @apiResourceAdditional status=201
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"name": ["The name field is required."]}}
     */
    public function store(StoreSystemPromptRequest $request): JsonResponse
    {
        $this->authorize('create', SystemPrompt::class);

        $prompt = SystemPrompt::create($request->validated());

        return (new SystemPromptResource($prompt))->response()->setStatusCode(201);
    }

    /**
     * Show System Prompt
     *
     * Get details of a specific system prompt.
     *
     * @urlParam systemPrompt integer required The system prompt ID. Example: 1
     *
     * @apiResource App\Http\Resources\SystemPromptResource
     *
     * @apiResourceModel App\Models\SystemPrompt
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\SystemPrompt] 1"}
     */
    public function show(SystemPrompt $systemPrompt): JsonResponse
    {
        $this->authorize('view', $systemPrompt);

        return (new SystemPromptResource($systemPrompt))->response();
    }

    /**
     * Update System Prompt
     *
     * Update an existing system prompt.
     *
     * @urlParam systemPrompt integer required The system prompt ID. Example: 1
     *
     * @bodyParam name string The prompt's name. Example: Updated Greeting
     * @bodyParam slug string Unique slug identifier. Example: updated-greeting
     * @bodyParam template string The Blade template content. Example: Hi {{ $name }}!
     * @bodyParam required_variables array List of required variable names. Example: ["name"]
     * @bodyParam default_values object Default values for variables. Example: {"name": "Guest"}
     * @bodyParam is_active boolean Whether the prompt is active. Example: false
     *
     * @apiResource App\Http\Resources\SystemPromptResource
     *
     * @apiResourceModel App\Models\SystemPrompt
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\SystemPrompt] 1"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"slug": ["The slug has already been taken."]}}
     */
    public function update(UpdateSystemPromptRequest $request, SystemPrompt $systemPrompt): JsonResponse
    {
        $this->authorize('update', $systemPrompt);

        $systemPrompt->update($request->validated());

        return (new SystemPromptResource($systemPrompt))->response();
    }

    /**
     * Delete System Prompt
     *
     * Delete a system prompt permanently.
     *
     * @urlParam systemPrompt integer required The system prompt ID. Example: 1
     *
     * @response 204 scenario="Success"
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\SystemPrompt] 1"}
     */
    public function destroy(SystemPrompt $systemPrompt): JsonResponse
    {
        $this->authorize('delete', $systemPrompt);

        $systemPrompt->delete();

        return response()->json(null, 204);
    }
}
