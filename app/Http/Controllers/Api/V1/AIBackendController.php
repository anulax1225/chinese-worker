<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AIBackendResource;
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
     *       "model": "llama3.1:latest",
     *       "status": "connected",
     *       "capabilities": {
     *         "streaming": true,
     *         "function_calling": false,
     *         "vision": false,
     *         "max_context": 4096
     *       },
     *       "models": []
     *     },
     *     {
     *       "name": "claude",
     *       "driver": "anthropic",
     *       "is_default": false,
     *       "status": "connected",
     *       "capabilities": {
     *         "streaming": true,
     *         "function_calling": true,
     *         "vision": true,
     *         "max_context": 200000
     *       },
     *       "models": []
     *     }
     *   ],
     *   "default_backend": "ollama"
     * }
     */
    public function index(): JsonResponse
    {
        $defaultBackend = config('ai.default');
        $backends = [];

        foreach (config('ai.backends', []) as $name => $config) {
            $backend = [
                'name' => $name,
                'driver' => $config['driver'],
                'is_default' => $name === $defaultBackend,
                'model' => $config['model'] ?? null,
                'capabilities' => [],
                'models' => [],
                'status' => 'unknown',
            ];

            try {
                $driver = $this->backendManager->driver($name);
                $backend['capabilities'] = $driver->getCapabilities();
                $backend['status'] = 'connected';

                // Try to list models (only for ollama which has local models)
                if ($config['driver'] === 'ollama') {
                    try {
                        $backend['models'] = $driver->listModels();
                    } catch (\Throwable $e) {
                        $backend['models'] = [];
                    }
                }
            } catch (\Throwable $e) {
                $backend['status'] = 'error';
                $backend['error'] = $e->getMessage();
            }

            $backends[] = $backend;
        }

        return response()->json([
            'backends' => AIBackendResource::collection($backends),
            'default_backend' => $defaultBackend,
        ]);
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
