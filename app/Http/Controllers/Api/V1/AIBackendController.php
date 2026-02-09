<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StreamsServerSentEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\PullModelRequest;
use App\Http\Resources\AIBackendResource;
use App\Jobs\PullModelJob;
use App\Services\AIBackendManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group AI Backend Management
 *
 * APIs for querying available AI backends and their capabilities.
 *
 * @authenticated
 */
class AIBackendController extends Controller
{
    use StreamsServerSentEvents;

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
     * @queryParam detailed boolean Fetch detailed model information including capabilities and context length. Default: false. Example: true
     *
     * @response 200 scenario="Basic" {"models": [{"name": "llama3.1:latest", "size": 4700000000, "modified_at": "2026-01-26T10:00:00Z"}]}
     * @response 200 scenario="Detailed" {"models": [{"name": "llama3.1:latest", "size": 4700000000, "capabilities": ["completion"], "context_length": 8192, "family": "llama", "parameter_size": "8B"}]}
     * @response 500 scenario="Backend Error" {"error": "Unable to retrieve models for this backend", "message": "Connection refused"}
     */
    public function models(Request $request, string $backend): JsonResponse
    {
        try {
            $driver = $this->backendManager->driver($backend);
            $detailed = $request->boolean('detailed', false);
            $models = $driver->listModels($detailed);

            return response()->json(['models' => $models]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to retrieve models for this backend',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pull Model
     *
     * Start pulling/downloading a model. Returns a pull ID for tracking progress.
     * Requires `manage-ai-models` gate permission.
     *
     * @urlParam backend string required The backend name. Example: ollama
     *
     * @bodyParam model string required The model name to pull. Example: llama3.1:latest
     *
     * @response 202 scenario="Queued" {"pull_id": "550e8400-e29b-41d4-a716-446655440000", "model": "llama3.1:latest", "backend": "ollama", "status": "queued", "stream_url": "/api/v1/ai-backends/ollama/models/pull/550e8400-e29b-41d4-a716-446655440000/stream"}
     * @response 400 scenario="Unsupported" {"error": "This backend does not support model management"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 500 scenario="Error" {"error": "Failed to initiate model pull", "message": "Connection refused"}
     */
    public function pullModel(PullModelRequest $request, string $backend): JsonResponse
    {
        Gate::authorize('manage-ai-models');

        try {
            $driver = $this->backendManager->driver($backend);

            if (! $driver->supportsModelManagement()) {
                return response()->json([
                    'error' => 'This backend does not support model management',
                ], 400);
            }

            $pullId = Str::uuid()->toString();

            PullModelJob::dispatch(
                backend: $backend,
                modelName: $request->input('model'),
                pullId: $pullId,
                userId: $request->user()->id,
            );

            return response()->json([
                'pull_id' => $pullId,
                'model' => $request->input('model'),
                'backend' => $backend,
                'status' => 'queued',
                'stream_url' => "/api/v1/ai-backends/{$backend}/models/pull/{$pullId}/stream",
            ], 202);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to initiate model pull',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stream Pull Progress
     *
     * Server-Sent Events stream for model pull progress updates.
     *
     * @urlParam backend string required The backend name. Example: ollama
     * @urlParam pullId string required The pull ID from pullModel response. Example: 550e8400-e29b-41d4-a716-446655440000
     *
     * @response 200 scenario="SSE Stream" {"event": "progress", "data": {"status": "downloading", "completed": 50, "total": 100}}
     */
    public function streamPullProgress(Request $request, string $backend, string $pullId): StreamedResponse
    {
        return response()->stream(
            function () use ($pullId): \Generator {
                yield $this->formatSSEEvent('connected', ['pull_id' => $pullId]);

                $channel = "model-pull:{$pullId}:events";
                $timeout = 2;

                try {
                    while (true) {
                        if (connection_aborted()) {
                            break;
                        }

                        $result = Redis::blpop($channel, $timeout);

                        if ($result) {
                            $payload = json_decode($result[1], true);

                            if ($payload && isset($payload['event'], $payload['data'])) {
                                yield $this->formatSSEEvent($payload['event'], $payload['data']);

                                if (in_array($payload['event'], ['completed', 'failed'])) {
                                    break;
                                }
                            }
                        }

                        yield $this->formatSSEHeartbeat();
                    }
                } catch (\Exception $e) {
                    yield $this->formatSSEEvent('error', ['message' => 'Stream error']);
                }
            },
            200,
            $this->getSSEHeaders()
        );
    }

    /**
     * Show Model Details
     *
     * Get detailed information about a specific model.
     * Requires `manage-ai-models` gate permission.
     *
     * @urlParam backend string required The backend name. Example: ollama
     * @urlParam model string required The model name. Example: llama3.1:latest
     *
     * @response 200 {"name": "llama3.1:latest", "family": "llama", "parameter_size": "8B", "quantization_level": "Q4_K_M", "capabilities": ["completion"], "context_length": 8192, "size": 4700000000, "size_human": "4.38 GB"}
     * @response 400 scenario="Unsupported" {"error": "This backend does not support model management"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 500 scenario="Error" {"error": "Failed to get model info", "message": "Model not found"}
     */
    public function showModel(string $backend, string $model): JsonResponse
    {
        Gate::authorize('manage-ai-models');

        try {
            $driver = $this->backendManager->driver($backend);

            if (! $driver->supportsModelManagement()) {
                return response()->json([
                    'error' => 'This backend does not support model management',
                ], 400);
            }

            $info = $driver->showModel($model);

            return response()->json($info->toArray());

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get model info',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Model
     *
     * Delete a model from the backend.
     * Requires `manage-ai-models` gate permission.
     *
     * @urlParam backend string required The backend name. Example: ollama
     * @urlParam model string required The model name to delete. Example: llama3.1:latest
     *
     * @response 200 {"message": "Model deleted successfully"}
     * @response 400 scenario="Unsupported" {"error": "This backend does not support model management"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 500 scenario="Error" {"error": "Failed to delete model", "message": "Model not found"}
     */
    public function deleteModel(string $backend, string $model): JsonResponse
    {
        Gate::authorize('manage-ai-models');

        try {
            $driver = $this->backendManager->driver($backend);

            if (! $driver->supportsModelManagement()) {
                return response()->json([
                    'error' => 'This backend does not support model management',
                ], 400);
            }

            $driver->deleteModel($model);

            return response()->json(['message' => 'Model deleted successfully']);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete model',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
