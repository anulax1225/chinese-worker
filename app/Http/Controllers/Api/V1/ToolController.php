<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreToolRequest;
use App\Http\Requests\UpdateToolRequest;
use App\Http\Resources\ToolResource;
use App\Models\Tool;
use App\Services\ToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Tool Management
 *
 * APIs for managing tools that agents can use.
 *
 * @authenticated
 */
class ToolController extends Controller
{
    public function __construct(
        protected ToolService $toolService
    ) {}

    /**
     * List Tools
     *
     * Get a paginated list of all tools for the authenticated user.
     * Includes builtin tools by default (Read, Write, Edit, Glob, Grep, Bash).
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     * @queryParam include_builtin boolean Include builtin tools in the response. Default: true. Example: true
     * @queryParam type string Filter by tool type (api, function, command, builtin). Example: builtin
     *
     * @response 200 {"data": [{"id": 1, "user_id": 1, "name": "API Tool", "type": "api", "config": {"url": "https://api.example.com"}, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}], "meta": {"current_page": 1, "per_page": 15, "total": 10, "last_page": 1}}
     */
    public function index(Request $request): JsonResponse
    {
        $includeBuiltin = $request->boolean('include_builtin', true);
        $typeFilter = $request->input('type');

        // Get user's custom tools
        $query = $request->user()->tools();

        if ($typeFilter && $typeFilter !== 'builtin') {
            $query->where('type', $typeFilter);
        }

        $userTools = $query->get();

        // Get builtin tools if requested
        $builtinTools = collect();
        if ($includeBuiltin && (! $typeFilter || $typeFilter === 'builtin')) {
            $builtinTools = collect($this->getBuiltinToolSchemas())
                ->map(fn ($tool) => [
                    'id' => 'builtin_'.strtolower($tool['name']),
                    'user_id' => null,
                    'name' => $tool['name'],
                    'type' => 'builtin',
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                    'created_at' => null,
                    'updated_at' => null,
                ]);
        }

        // If filtering for builtin only, return just builtin tools
        if ($typeFilter === 'builtin') {
            return response()->json([
                'data' => $builtinTools->values(),
            ]);
        }

        // Merge user tools with builtin tools
        $allTools = $builtinTools->merge(
            $userTools->map(fn ($tool) => $tool->toArray())
        );

        // Manual pagination
        $perPage = (int) $request->input('per_page', 15);
        $page = (int) $request->input('page', 1);
        $total = $allTools->count();

        $paginatedTools = $allTools->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $paginatedTools,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Create Tool
     *
     * Create a new tool.
     *
     * @bodyParam name string required The tool's name. Example: Weather API
     * @bodyParam type string required The tool type. Must be one of: api, function, command. Example: api
     * @bodyParam config object required The tool's configuration. Example: {"url": "https://api.weather.com", "method": "GET"}
     *
     * @apiResource App\Http\Resources\ToolResource
     *
     * @apiResourceModel App\Models\Tool
     *
     * @apiResourceAdditional status=201
     *
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"name": ["The name field is required."]}}
     */
    public function store(StoreToolRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        $tool = $this->toolService->create($data);

        return (new ToolResource($tool))->response()->setStatusCode(201);
    }

    /**
     * Show Tool
     *
     * Get details of a specific tool.
     *
     * @urlParam tool integer required The tool ID. Example: 1
     *
     * @apiResource App\Http\Resources\ToolResource
     *
     * @apiResourceModel App\Models\Tool
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Tool] 1"}
     */
    public function show(Tool $tool): JsonResponse
    {
        $this->authorize('view', $tool);

        return (new ToolResource($tool))->response();
    }

    /**
     * Update Tool
     *
     * Update an existing tool.
     *
     * @urlParam tool integer required The tool ID. Example: 1
     *
     * @bodyParam name string The tool's name. Example: Updated Weather API
     * @bodyParam type string The tool type. Must be one of: api, function, command. Example: api
     * @bodyParam config object The tool's configuration. Example: {"url": "https://api.weather.com/v2"}
     *
     * @apiResource App\Http\Resources\ToolResource
     *
     * @apiResourceModel App\Models\Tool
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Tool] 1"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"type": ["The selected type is invalid."]}}
     */
    public function update(UpdateToolRequest $request, Tool $tool): JsonResponse
    {
        $this->authorize('update', $tool);

        $tool = $this->toolService->update($tool, $request->validated());

        return (new ToolResource($tool))->response();
    }

    /**
     * Delete Tool
     *
     * Delete a tool permanently.
     *
     * @urlParam tool integer required The tool ID. Example: 1
     *
     * @response 204 scenario="Success"
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Tool] 1"}
     */
    public function destroy(Tool $tool): JsonResponse
    {
        $this->authorize('delete', $tool);

        $this->toolService->delete($tool);

        return response()->json(null, 204);
    }

    /**
     * Get builtin tool schemas.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getBuiltinToolSchemas(): array
    {
        return [
            [
                'name' => 'bash',
                'description' => 'Execute a bash command on the client system',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => 'The bash command to execute',
                        ],
                    ],
                    'required' => ['command'],
                ],
            ],
            [
                'name' => 'read',
                'description' => 'Read the contents of a file',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Path to the file to read',
                        ],
                    ],
                    'required' => ['file_path'],
                ],
            ],
            [
                'name' => 'write',
                'description' => 'Write content to a file',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Path to the file to write',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Content to write to the file',
                        ],
                    ],
                    'required' => ['file_path', 'content'],
                ],
            ],
            [
                'name' => 'edit',
                'description' => 'Edit a file by replacing old text with new text',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Path to the file to edit',
                        ],
                        'old_string' => [
                            'type' => 'string',
                            'description' => 'The text to find and replace',
                        ],
                        'new_string' => [
                            'type' => 'string',
                            'description' => 'The text to replace with',
                        ],
                    ],
                    'required' => ['file_path', 'old_string', 'new_string'],
                ],
            ],
            [
                'name' => 'glob',
                'description' => 'Find files matching a pattern',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => [
                            'type' => 'string',
                            'description' => 'Glob pattern to match files',
                        ],
                    ],
                    'required' => ['pattern'],
                ],
            ],
            [
                'name' => 'grep',
                'description' => 'Search for a pattern in files',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => [
                            'type' => 'string',
                            'description' => 'Pattern to search for',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'Path to search in',
                        ],
                    ],
                    'required' => ['pattern'],
                ],
            ],
        ];
    }
}
