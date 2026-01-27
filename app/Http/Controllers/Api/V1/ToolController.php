<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreToolRequest;
use App\Http\Requests\UpdateToolRequest;
use App\Models\Tool;
use App\Services\AgentLoop\BuiltinToolExecutor;
use App\Services\ToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Tool Management
 *
 * APIs for managing tools that agents can use
 */
class ToolController extends Controller
{
    public function __construct(
        protected ToolService $toolService,
        protected BuiltinToolExecutor $builtinToolExecutor
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
     * @response 200 {"data": [{"id": 1, "user_id": 1, "name": "API Tool", "type": "api", "config": {"url": "https://api.example.com"}, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}]}
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
            $builtinTools = collect($this->builtinToolExecutor->getBuiltinTools())
                ->map(fn ($tool) => [
                    'id' => 'builtin_'.strtolower($tool->getName()),
                    'user_id' => null,
                    'name' => $tool->getName(),
                    'type' => 'builtin',
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameterSchema(),
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
     * @response 201 {"id": 1, "user_id": 1, "name": "Weather API", "type": "api", "config": {"url": "https://api.weather.com"}, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}
     */
    public function store(StoreToolRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        $tool = $this->toolService->create($data);

        return response()->json($tool, 201);
    }

    /**
     * Show Tool
     *
     * Get details of a specific tool.
     *
     * @urlParam tool integer required The tool ID. Example: 1
     *
     * @response 200 {"id": 1, "user_id": 1, "name": "Weather API", "type": "api", "config": {"url": "https://api.weather.com"}, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}
     */
    public function show(Tool $tool): JsonResponse
    {
        $this->authorize('view', $tool);

        return response()->json($tool);
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
     * @response 200 {"id": 1, "user_id": 1, "name": "Updated Weather API", "type": "api", "config": {"url": "https://api.weather.com/v2"}, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T11:00:00.000000Z"}
     */
    public function update(UpdateToolRequest $request, Tool $tool): JsonResponse
    {
        $this->authorize('update', $tool);

        $tool = $this->toolService->update($tool, $request->validated());

        return response()->json($tool);
    }

    /**
     * Delete Tool
     *
     * Delete a tool permanently.
     *
     * @urlParam tool integer required The tool ID. Example: 1
     *
     * @response 204
     */
    public function destroy(Tool $tool): JsonResponse
    {
        $this->authorize('delete', $tool);

        $this->toolService->delete($tool);

        return response()->json(null, 204);
    }
}
