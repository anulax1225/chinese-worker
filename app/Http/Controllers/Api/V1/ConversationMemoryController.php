<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecallMessagesRequest;
use App\Jobs\EmbedConversationMessagesJob;
use App\Models\Conversation;
use App\Models\MessageEmbedding;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * @group Conversation Memory
 *
 * APIs for semantic search within conversation history.
 *
 * Conversation Memory allows you to search previous messages using semantic
 * similarity. Messages must first be embedded before they can be searched.
 *
 * **Note:** These endpoints require RAG to be enabled in configuration.
 *
 * @authenticated
 */
class ConversationMemoryController extends Controller
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * Recall Messages
     *
     * Search conversation history using semantic similarity. Returns messages
     * that are semantically similar to the query, ordered by similarity score.
     *
     * This is useful for:
     * - Finding earlier discussions about a topic
     * - Retrieving context from previous parts of the conversation
     * - Locating specific information mentioned previously
     *
     * @urlParam conversation integer required The conversation ID. Example: 1
     *
     * @bodyParam query string required The search query (min 1 char, max 2000). Example: "What did we discuss about authentication?"
     * @bodyParam top_k integer Maximum number of results to return (1-50). Default: 5. Example: 5
     * @bodyParam threshold number Minimum similarity threshold (0-1). Lower values return more results. Default: 0.3. Example: 0.3
     * @bodyParam hybrid boolean Use hybrid search (combines semantic + keyword matching). Default: false. Example: false
     *
     * @response 200 scenario="Success" {
     *   "query": "What did we discuss about authentication?",
     *   "matches": [
     *     {
     *       "message_id": "01H5K3MPXJ9RWVZ8NCQY7D6WGT",
     *       "conversation_id": 1,
     *       "role": "assistant",
     *       "content": "For authentication, I recommend using Laravel Sanctum for API token-based auth...",
     *       "position": 15,
     *       "similarity": 0.8542,
     *       "created_at": "2024-01-15T10:30:00.000000Z"
     *     }
     *   ],
     *   "count": 1,
     *   "threshold": 0.3,
     *   "hybrid": false
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 1"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"query": ["The query field is required."]}}
     */
    public function recall(RecallMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $query = $request->validated('query');
        $topK = $request->validated('top_k', 5);
        $threshold = $request->validated('threshold', 0.3);
        $useHybrid = $request->validated('hybrid', false);

        // Generate query embedding
        $queryEmbedding = $this->embeddingService->embed($query);

        // Search for similar messages
        $embeddingsQuery = MessageEmbedding::query()
            ->forConversation($conversation->id)
            ->withEmbeddings()
            ->with('message');

        if ($useHybrid) {
            $sparseVector = $this->embeddingService->generateSparseEmbedding($query);
            $results = $embeddingsQuery
                ->hybridSearch($queryEmbedding, $sparseVector, $topK, 0.7, $threshold)
                ->get();
        } else {
            $results = $embeddingsQuery
                ->semanticSearch($queryEmbedding, $topK, $threshold)
                ->get();
        }

        // Record access for analytics
        foreach ($results as $result) {
            $result->recordAccess();
        }

        // Format response
        $matches = $results->map(fn (MessageEmbedding $e) => [
            'message_id' => $e->message_id,
            'conversation_id' => $e->conversation_id,
            'role' => $e->message?->role,
            'content' => $e->message?->content,
            'position' => $e->message?->position,
            'similarity' => round($e->similarity ?? $e->hybrid_score ?? 0, 4),
            'created_at' => $e->message?->created_at?->toISOString(),
        ]);

        return response()->json([
            'query' => $query,
            'matches' => $matches,
            'count' => $matches->count(),
            'threshold' => $threshold,
            'hybrid' => $useHybrid,
        ]);
    }

    /**
     * Embed Messages
     *
     * Trigger embedding generation for all un-embedded messages in a conversation.
     * The embeddings are generated asynchronously via a background job.
     *
     * Embeddings must be generated before messages can be searched using the
     * recall endpoint. Only user and assistant messages are embedded.
     *
     * @urlParam conversation integer required The conversation ID. Example: 1
     *
     * @response 202 scenario="Job Dispatched" {
     *   "message": "Embedding job dispatched",
     *   "pending_count": 15
     * }
     * @response 200 scenario="Already Complete" {
     *   "message": "All messages already have embeddings",
     *   "pending_count": 0
     * }
     * @response 400 scenario="RAG Disabled" {"error": "RAG is not enabled"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 1"}
     */
    public function embed(Conversation $conversation): JsonResponse
    {
        Gate::authorize('update', $conversation);

        // Check if RAG is enabled
        if (! config('ai.rag.enabled', false)) {
            return response()->json([
                'error' => 'RAG is not enabled',
            ], 400);
        }

        // Count messages needing embedding
        $pendingCount = $conversation->conversationMessages()
            ->whereIn('role', ['user', 'assistant'])
            ->whereDoesntHave('embedding', fn ($q) => $q->whereNotNull('embedding_generated_at'))
            ->count();

        if ($pendingCount === 0) {
            return response()->json([
                'message' => 'All messages already have embeddings',
                'pending_count' => 0,
            ]);
        }

        // Dispatch job
        EmbedConversationMessagesJob::dispatch($conversation);

        return response()->json([
            'message' => 'Embedding job dispatched',
            'pending_count' => $pendingCount,
        ], 202);
    }

    /**
     * Memory Status
     *
     * Get the embedding status for a conversation. Shows how many messages
     * have been embedded and are available for semantic search.
     *
     * @urlParam conversation integer required The conversation ID. Example: 1
     *
     * @response 200 scenario="Success" {
     *   "conversation_id": 1,
     *   "total_messages": 50,
     *   "embedded_count": 45,
     *   "pending_count": 5,
     *   "completion_percentage": 90,
     *   "rag_enabled": true
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 1"}
     */
    public function status(Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $totalMessages = $conversation->conversationMessages()
            ->whereIn('role', ['user', 'assistant'])
            ->count();

        $embeddedCount = MessageEmbedding::query()
            ->forConversation($conversation->id)
            ->withEmbeddings()
            ->count();

        $pendingCount = $totalMessages - $embeddedCount;

        return response()->json([
            'conversation_id' => $conversation->id,
            'total_messages' => $totalMessages,
            'embedded_count' => $embeddedCount,
            'pending_count' => $pendingCount,
            'completion_percentage' => $totalMessages > 0
                ? round(($embeddedCount / $totalMessages) * 100, 1)
                : 100,
            'rag_enabled' => config('ai.rag.enabled', false),
        ]);
    }
}
