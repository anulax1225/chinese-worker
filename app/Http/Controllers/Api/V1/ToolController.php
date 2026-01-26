<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreToolRequest;
use App\Http\Requests\UpdateToolRequest;
use App\Models\Tool;
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
    public function __construct(protected ToolService $toolService) {}

    /**
     * List Tools
     *
     * Get a paginated list of all tools for the authenticated user.
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     *
     * @response 200 {"data": [{"id": 1, "user_id": 1, "name": "API Tool", "type": "api", "config": {"url": "https://api.example.com"}, "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $tools = $request->user()
            ->tools()
            ->paginate($request->input('per_page', 15));

        return response()->json($tools);
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
