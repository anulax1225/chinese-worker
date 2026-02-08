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
 * APIs for querying available AI backends and their capabilities
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

    /**
     * Pull Model
     *
     * Start pulling/downloading a model. Returns a pull ID for tracking progress.
     *
     * @urlParam backend string required The backend name. Example: ollama
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
     * SSE stream for model pull progress updates.
     */
    public function streamPullProgress(Request $request, string $backend, string $pullId): StreamedResponse
    {
        return response()->stream(
            function () use ($pullId) {
                $this->prepareSSEStream();

                $this->sendSSEEvent('connected', ['pull_id' => $pullId]);

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
                                $this->sendSSEEvent($payload['event'], $payload['data']);

                                if (in_array($payload['event'], ['completed', 'failed'])) {
                                    break;
                                }
                            }
                        }

                        $this->sendSSEHeartbeat();
                    }
                } catch (\Exception $e) {
                    $this->sendSSEEvent('error', ['message' => 'Stream error']);
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
