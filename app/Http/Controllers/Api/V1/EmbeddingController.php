<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EmbeddingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompareEmbeddingRequest;
use App\Http\Requests\GenerateEmbeddingRequest;
use App\Http\Requests\StoreAsyncEmbeddingRequest;
use App\Http\Resources\EmbeddingResource;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\Embedding;
use App\Services\AIBackendManager;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\VectorSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Embeddings
 *
 * APIs for generating and managing text embeddings.
 *
 * @authenticated
 */
class EmbeddingController extends Controller
{
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected AIBackendManager $backendManager,
        protected VectorSearchService $vectorSearchService,
    ) {}

    /**
     * List Embeddings
     *
     * Get a paginated list of stored embeddings for the authenticated user.
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     * @queryParam status string Filter by status. Example: completed
     *
     * @apiResourceCollection App\Http\Resources\EmbeddingResource
     *
     * @apiResourceModel App\Models\Embedding paginate=15
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()
            ->embeddings()
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $embeddings = $query->paginate($request->input('per_page', 15));

        return EmbeddingResource::collection($embeddings);
    }

    /**
     * Generate Embeddings (Sync)
     *
     * Generate embedding vector(s) for one or more text inputs synchronously.
     *
     * @bodyParam input string|array required Text(s) to embed. Example: "Hello world"
     * @bodyParam model string Optional model override. Example: qwen3-embedding:4b
     *
     * @response 200 {"object":"list","data":[{"object":"embedding","index":0,"embedding":[0.1,...]}],"model":"qwen3-embedding:4b","usage":{"prompt_tokens":null,"total_tokens":null}}
     * @response 400 scenario="RAG Disabled" {"error": "Embedding service is not enabled"}
     * @response 422 scenario="Validation Error" {"message":"...","errors":{"input":["..."]}}
     */
    public function store(GenerateEmbeddingRequest $request): JsonResponse
    {
        if (! config('ai.rag.enabled', false)) {
            return response()->json([
                'error' => 'Embedding service is not enabled',
            ], 400);
        }

        $texts = $request->getTexts();
        $model = $request->getModel() ?? config('ai.rag.embedding_model');

        try {
            $embeddings = count($texts) === 1
                ? [$this->embeddingService->embed($texts[0], $model)]
                : $this->embeddingService->embedBatch($texts, $model);

            $data = collect($embeddings)->map(fn (array $embedding, int $index) => [
                'object' => 'embedding',
                'index' => $index,
                'embedding' => $embedding,
            ]);

            return response()->json([
                'object' => 'list',
                'data' => $data,
                'model' => $model,
                'usage' => [
                    'prompt_tokens' => null,
                    'total_tokens' => null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate embeddings',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate Embedding (Async)
     *
     * Queue an embedding job for background processing. Poll the returned
     * embedding ID to check status.
     *
     * @bodyParam text string required Text to embed. Example: "Hello world"
     * @bodyParam model string Optional model override. Example: qwen3-embedding:4b
     *
     * @apiResource App\Http\Resources\EmbeddingResource
     *
     * @apiResourceModel App\Models\Embedding
     *
     * @response 400 scenario="RAG Disabled" {"error": "Embedding service is not enabled"}
     * @response 422 scenario="Validation Error" {"message":"...","errors":{"text":["..."]}}
     */
    public function storeAsync(StoreAsyncEmbeddingRequest $request): JsonResponse
    {
        if (! config('ai.rag.enabled', false)) {
            return response()->json([
                'error' => 'Embedding service is not enabled',
            ], 400);
        }

        $text = $request->input('text');
        $model = $request->input('model') ?? config('ai.rag.embedding_model');

        $embedding = Embedding::create([
            'user_id' => $request->user()->id,
            'text' => $text,
            'text_hash' => Embedding::hashText($text, $model),
            'model' => $model,
            'status' => EmbeddingStatus::Pending,
        ]);

        GenerateEmbeddingJob::dispatch($embedding);

        return (new EmbeddingResource($embedding))->response()->setStatusCode(202);
    }

    /**
     * Show Embedding
     *
     * Get a specific embedding. Use this to poll for async job completion.
     *
     * @urlParam embedding integer required The embedding ID. Example: 1
     *
     * @apiResource App\Http\Resources\EmbeddingResource
     *
     * @apiResourceModel App\Models\Embedding
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Embedding] 1"}
     */
    public function show(Request $request, Embedding $embedding): JsonResponse
    {
        if ($embedding->user_id !== $request->user()->id) {
            abort(403, 'This action is unauthorized.');
        }

        return (new EmbeddingResource($embedding))->response();
    }

    /**
     * Delete Embedding
     *
     * Delete a stored embedding.
     *
     * @urlParam embedding integer required The embedding ID. Example: 1
     *
     * @response 204 scenario="Success"
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Embedding] 1"}
     */
    public function destroy(Request $request, Embedding $embedding): JsonResponse
    {
        if ($embedding->user_id !== $request->user()->id) {
            abort(403, 'This action is unauthorized.');
        }

        $embedding->delete();

        return response()->json(null, 204);
    }

    /**
     * List Embedding Models
     *
     * Get available embedding models from the configured backend.
     *
     * @response 200 {"models":[{"name":"qwen3-embedding:4b","size":2500000000}]}
     * @response 400 scenario="RAG Disabled" {"error": "Embedding service is not enabled"}
     */
    public function models(): JsonResponse
    {
        if (! config('ai.rag.enabled', false)) {
            return response()->json([
                'error' => 'Embedding service is not enabled',
            ], 400);
        }

        $backendName = config('ai.rag.embedding_backend', 'ollama');

        try {
            $backend = $this->backendManager->driver($backendName);
            $models = $backend->listModels();

            // Filter to likely embedding models (name contains 'embed')
            $embeddingModels = array_filter($models, function ($model) {
                $name = strtolower($model['name'] ?? '');

                return str_contains($name, 'embed');
            });

            return response()->json([
                'models' => array_values($embeddingModels),
                'default_model' => config('ai.rag.embedding_model'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to list models',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compare Embeddings
     *
     * Compare a source embedding against one or more targets using cosine similarity.
     * Both source and targets can be specified by stored embedding ID or raw text.
     *
     * @bodyParam source object required The source embedding. Must have either "id" or "text".
     * @bodyParam targets array required Array of target embeddings. Each must have either "id" or "text".
     * @bodyParam model string Optional model override for on-the-fly text embeddings.
     *
     * @response 200 {"source":{"id":1,"text":"..."},"results":[{"target":{"id":2,"text":"..."},"similarity":0.85}],"model":"qwen3-embedding:4b"}
     * @response 400 scenario="RAG Disabled" {"error":"Embedding service is not enabled"}
     * @response 403 scenario="Forbidden" {"message":"This action is unauthorized."}
     */
    public function compare(CompareEmbeddingRequest $request): JsonResponse
    {
        if (! config('ai.rag.enabled', false)) {
            return response()->json([
                'error' => 'Embedding service is not enabled',
            ], 400);
        }

        $model = $request->input('model') ?? config('ai.rag.embedding_model');

        try {
            $sourceData = $request->input('source');
            $sourceVector = $this->resolveVector($sourceData, $model);
            $sourceInfo = $this->resolveInfo($sourceData);

            $results = [];

            foreach ($request->input('targets') as $targetData) {
                $targetVector = $this->resolveVector($targetData, $model);
                $targetInfo = $this->resolveInfo($targetData);
                $similarity = $this->vectorSearchService->cosineSimilarity($sourceVector, $targetVector);

                $results[] = [
                    'target' => $targetInfo,
                    'similarity' => round($similarity, 6),
                ];
            }

            // Sort by similarity descending
            usort($results, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

            return response()->json([
                'source' => $sourceInfo,
                'results' => $results,
                'model' => $model,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to compare embeddings',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Embedding Configuration
     *
     * Get the current embedding service configuration.
     *
     * @response 200 {"enabled":true,"model":"qwen3-embedding:4b","backend":"ollama","dimensions":30000,"batch_size":100,"caching_enabled":true}
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'enabled' => config('ai.rag.enabled', false),
            'model' => config('ai.rag.embedding_model'),
            'backend' => config('ai.rag.embedding_backend'),
            'dimensions' => config('ai.rag.embedding_dimensions'),
            'batch_size' => config('ai.rag.embedding_batch_size'),
            'caching_enabled' => config('ai.rag.cache_embeddings'),
        ]);
    }

    /**
     * Resolve a vector from an embedding reference (ID or text).
     *
     * @param  array<string, mixed>  $data
     * @return array<float>
     */
    protected function resolveVector(array $data, string $model): array
    {
        if (! empty($data['id'])) {
            $embedding = Embedding::findOrFail($data['id']);

            if ($embedding->embedding_raw === null) {
                throw new \RuntimeException("Embedding {$embedding->id} has no vector data (status: {$embedding->status->value})");
            }

            return $embedding->embedding_raw;
        }

        return $this->embeddingService->embed($data['text'], $model);
    }

    /**
     * Resolve display info from an embedding reference.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolveInfo(array $data): array
    {
        if (! empty($data['id'])) {
            $embedding = Embedding::findOrFail($data['id']);

            return ['id' => $embedding->id, 'text' => $embedding->text];
        }

        return ['text' => $data['text']];
    }
}
