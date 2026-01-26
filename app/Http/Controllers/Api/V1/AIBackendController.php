<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AIBackendManager;
use Illuminate\Http\JsonResponse;

/**
 * @group AI Backend Management
 *
 * APIs for querying available AI backends and their capabilities
 */
class AIBackendController extends Controller
{
    public function __construct(protected AIBackendManager $backendManager) {}

    /**
     * List AI Backends
     *
     * Get a list of all available AI backends and their capabilities.
     *
     * @response 200 {
     *   "backends": [
     *     {
     *       "name": "ollama",
     *       "driver": "ollama",
     *       "is_default": true,
     *       "capabilities": {
     *         "streaming": true,
     *         "function_calling": false,
     *         "vision": false,
     *         "max_context": 4096
     *       }
     *     },
     *     {
     *       "name": "claude",
     *       "driver": "anthropic",
     *       "is_default": false,
     *       "capabilities": {
     *         "streaming": true,
     *         "function_calling": true,
     *         "vision": true,
     *         "max_context": 200000
     *       }
     *     }
     *   ]
     * }
     */
    public function index(): JsonResponse
    {
        $defaultBackend = config('ai.default');
        $backends = [];

        foreach (config('ai.backends') as $name => $config) {
            try {
                $driver = $this->backendManager->driver($name);

                $backends[] = [
                    'name' => $name,
                    'driver' => $config['driver'],
                    'is_default' => $name === $defaultBackend,
                    'capabilities' => $driver->getCapabilities(),
                ];
            } catch (\Exception $e) {
                // Skip backends that fail to initialize
            }
        }

        return response()->json(['backends' => $backends]);
    }

    /**
     * List Backend Models
     *
     * Get a list of available models for a specific AI backend.
     *
     * @urlParam backend string required The backend name (ollama, claude, openai). Example: ollama
     *
     * @response 200 {
     *   "models": [
     *     {
     *       "name": "llama3.1:latest",
     *       "size": 4700000000,
     *       "modified": "2026-01-26T10:00:00.000000Z"
     *     },
     *     {
     *       "name": "codellama:latest",
     *       "size": 3800000000,
     *       "modified": "2026-01-25T15:30:00.000000Z"
     *     }
     *   ]
     * }
     */
    public function models(string $backend): JsonResponse
    {
        try {
            $driver = $this->backendManager->driver($backend);
            $models = $driver->listModels();

            return response()->json(['models' => $models]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to retrieve models for this backend',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
