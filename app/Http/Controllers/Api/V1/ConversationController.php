<?php

namespace App\Http\Controllers\Api\V1;

use App\DTOs\ToolResult;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Concerns\StreamsServerSentEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\SubmitToolResultRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\ConversationEventBroadcaster;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Conversation Management
 *
 * APIs for managing AI agent conversations
 *
 * @authenticated
 */
class ConversationController extends Controller
{
    use StreamsServerSentEvents;

    public function __construct(protected ConversationService $conversationService) {}

    /**
     * Create Conversation
     *
     * Create a new conversation with an agent.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam title string Optional title for the conversation. Example: Debug authentication issue
     * @bodyParam metadata object Optional metadata. Example: {}
     *
     * @apiResource App\Http\Resources\ConversationResource
     *
     * @apiResourceModel App\Models\Conversation with=agent
     *
     * @apiResourceAdditional status=201
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Agent Not Found" {"message": "No query results for model [App\\Models\\Agent] 1"}
     */
    public function store(StoreConversationRequest $request, Agent $agent): JsonResponse
    {
        $conversation = Conversation::create([
            'agent_id' => $agent->id,
            'user_id' => $request->user()->id,
            'status' => 'active',
            'messages' => [],
            'metadata' => $request->input('metadata', []),
            'turn_count' => 0,
            'total_tokens' => 0,
            'client_type' => $request->input('client_type'),
            'client_tool_schemas' => $request->input('client_tool_schemas', []),
        ]);

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Send Message
     *
     * Send a message to a conversation. Server processes through agentic loop.
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @bodyParam content string required The message content. Example: What files are in /tmp?
     * @bodyParam images array Optional base64-encoded images for vision. Example: []
     * @bodyParam document_ids array Optional document IDs to attach. Example: [1, 2]
     *
     * @response 202 {"status": "processing", "conversation_id": 123, "check_url": "/api/v1/conversations/123/status"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 123"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"content": ["The content field is required."]}}
     * @response 500 scenario="Processing Error" {"status": "failed", "conversation_id": 123, "error": "AI backend error"}
     */
    public function sendMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $documentIds = $request->input('document_ids', []);

        // Attach documents to conversation if provided
        if (! empty($documentIds)) {
            $this->attachDocuments($conversation, $request->user(), $documentIds);
        }

        // Add user's prompt as first message
        $userMessage = $this->conversationService->addUserMessage(
            $conversation,
            $request->input('content'),
            $request->input('images')
        );

        // Link documents as attachments on the user message
        if (! empty($documentIds)) {
            $documents = $conversation->documents()->whereIn('documents.id', $documentIds)->get();
            foreach ($documents as $document) {
                $userMessage->attachments()->create([
                    'type' => MessageAttachment::TYPE_DOCUMENT,
                    'document_id' => $document->id,
                    'filename' => $document->title,
                    'mime_type' => $document->mime_type,
                ]);
            }
        }

        // Start processing (dispatch job)
        $state = $this->conversationService->startProcessing($conversation);

        // Return appropriate response based on state
        if ($state->status === 'waiting_for_tool') {
            return response()->json($state->toPollingResponse());
        }

        if ($state->status === 'completed') {
            return response()->json($state->toPollingResponse());
        }

        if ($state->status === 'failed') {
            return response()->json($state->toPollingResponse(), 500);
        }

        // Processing
        return response()->json([
            'status' => 'processing',
            'conversation_id' => $conversation->id,
            'check_url' => "/api/v1/conversations/{$conversation->id}/status",
        ], 202);
    }

    /**
     * Get Conversation Status
     *
     * Poll for conversation status (used with polling mode).
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @response 200 {"status": "completed", "conversation_id": 123, "messages": [{"role": "assistant", "content": "I found 2 files in /tmp..."}], "stats": {"turns": 5, "tokens": 450}}
     * @response 200 scenario="Processing" {"status": "processing", "conversation_id": 123, "stats": {"turns": 2, "tokens": 150}}
     * @response 200 scenario="Waiting for Tool" {"status": "waiting_for_tool", "conversation_id": 123, "tool_request": {"name": "bash", "arguments": {"command": "ls /tmp"}}, "submit_url": "/api/v1/conversations/123/tool-results"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 123"}
     */
    public function status(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->refresh();

        // Map database status to CLI-expected status
        $cliStatus = match (true) {
            $conversation->isWaitingForTool() => 'waiting_for_tool',
            $conversation->status === 'active' => 'processing',
            $conversation->status === 'paused' => 'processing', // Job still processing
            default => $conversation->status, // completed, failed, cancelled
        };

        $response = [
            'status' => $cliStatus,
            'conversation_id' => $conversation->id,
        ];

        if ($conversation->isWaitingForTool()) {
            $response['tool_request'] = $conversation->pending_tool_request;
            $response['submit_url'] = "/api/v1/conversations/{$conversation->id}/tool-results";
        }

        $response['stats'] = [
            'turns' => $conversation->turn_count,
            'tokens' => $conversation->total_tokens,
        ];

        // Include last assistant message if conversation is completed
        if ($conversation->status === 'completed') {
            $messages = $conversation->getMessages();
            $lastMessage = end($messages);

            if ($lastMessage && $lastMessage->role === 'assistant') {
                $response['messages'] = [$lastMessage->toArray()];
            }
        }

        return response()->json($response);
    }

    /**
     * Stop Conversation
     *
     * Stop a running conversation and mark it as cancelled.
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @response 200 {"status": "cancelled", "conversation_id": 123}
     * @response 200 scenario="Not Running" {"status": "completed", "message": "Conversation is not running"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 123"}
     */
    public function stop(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        // Only stop if conversation is actively processing
        if (! in_array($conversation->status, ['active', 'paused'])) {
            return response()->json([
                'status' => $conversation->status,
                'message' => 'Conversation is not running',
            ]);
        }

        $conversation->markAsCancelled();

        // Broadcast cancellation event to SSE stream
        app(ConversationEventBroadcaster::class)->cancelled($conversation);

        return response()->json([
            'status' => 'cancelled',
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Submit Tool Result
     *
     * Submit the result of a builtin tool execution from CLI.
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @bodyParam call_id string required The tool call ID. Example: call_abc123
     * @bodyParam success boolean required Whether tool execution succeeded. Example: true
     * @bodyParam output string The tool output (if successful). Example: file1.txt\nfile2.txt
     * @bodyParam error string The error message (if failed). Example: File not found
     *
     * @response 200 {"status": "processing", "conversation_id": 123}
     * @response 200 scenario="Waiting for Another Tool" {"status": "waiting_for_tool", "conversation_id": 123, "tool_request": {"name": "read", "arguments": {"file_path": "/tmp/file1.txt"}}}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 123"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"call_id": ["The call_id field is required."]}}
     * @response 500 scenario="Processing Error" {"status": "failed", "conversation_id": 123, "error": "AI backend error"}
     */
    public function submitToolResult(SubmitToolResultRequest $request, Conversation $conversation): JsonResponse
    {
        $result = new ToolResult(
            success: $request->boolean('success'),
            output: $request->input('output') ?? '',
            error: $request->input('error')
        );

        // Resume conversation with tool result
        $state = $this->conversationService->submitToolResult(
            $conversation,
            $request->input('call_id'),
            $result
        );

        // Return appropriate response based on state
        if ($state->status === 'waiting_for_tool') {
            return response()->json($state->toPollingResponse());
        }

        if ($state->status === 'completed') {
            return response()->json($state->toPollingResponse());
        }

        if ($state->status === 'failed') {
            return response()->json($state->toPollingResponse(), 500);
        }

        // Still processing
        return response()->json([
            'status' => 'processing',
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Stream Conversation Events
     *
     * Open a Server-Sent Events stream for real-time conversation updates.
     * Returns SSE events: `connected`, `tool_request`, `completed`, `failed`, `cancelled`.
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @response 200 scenario="SSE Stream" {"event": "connected", "data": {"conversation_id": 123, "status": "connected"}}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 123"}
     */
    public function stream(Conversation $conversation): StreamedResponse
    {
        $this->authorize('view', $conversation);

        return response()->stream(
            function () use ($conversation): \Generator {
                // Send initial connected event
                yield $this->formatSSEEvent('connected', [
                    'conversation_id' => $conversation->id,
                    'status' => 'connected',
                ]);

                // Check current conversation state and send if already terminal
                // This handles the race condition where job finishes before SSE connects
                $conversation->refresh();

                if ($conversation->isWaitingForTool()) {
                    yield $this->formatSSEEvent('tool_request', [
                        'status' => 'waiting_for_tool',
                        'conversation_id' => $conversation->id,
                        'tool_request' => $conversation->pending_tool_request,
                        'submit_url' => "/api/v1/conversations/{$conversation->id}/tool-results",
                        'stats' => [
                            'turns' => $conversation->turn_count,
                            'tokens' => $conversation->total_tokens,
                        ],
                    ]);

                    return;
                }

                if ($conversation->status === 'completed') {
                    $messages = $conversation->getMessages();
                    $lastMessage = end($messages);
                    $data = [
                        'status' => 'completed',
                        'conversation_id' => $conversation->id,
                        'stats' => [
                            'turns' => $conversation->turn_count,
                            'tokens' => $conversation->total_tokens,
                        ],
                    ];
                    if ($lastMessage && $lastMessage->role === 'assistant') {
                        $data['messages'] = [$lastMessage->toArray()];
                    }
                    yield $this->formatSSEEvent('completed', $data);

                    return;
                }

                if ($conversation->status === 'failed') {
                    yield $this->formatSSEEvent('failed', [
                        'status' => 'failed',
                        'conversation_id' => $conversation->id,
                        'error' => 'Conversation failed',
                        'stats' => [
                            'turns' => $conversation->turn_count,
                            'tokens' => $conversation->total_tokens,
                        ],
                    ]);

                    return;
                }

                if ($conversation->status === 'cancelled') {
                    yield $this->formatSSEEvent('cancelled', [
                        'status' => 'cancelled',
                        'conversation_id' => $conversation->id,
                        'stats' => [
                            'turns' => $conversation->turn_count,
                            'tokens' => $conversation->total_tokens,
                        ],
                    ]);

                    return;
                }

                // Listen to Redis list for this conversation
                // Using BLPOP with timeout instead of blocking SUBSCRIBE to prevent PHP worker deadlock
                $channel = "conversation:{$conversation->id}:events";
                $timeout = 2; // 2 second timeout for BLPOP - allows periodic client disconnect check

                // Release database connection before loop - we only need Redis from here
                DB::disconnect();

                try {
                    while (true) {
                        // Check if client disconnected
                        if (connection_aborted()) {
                            break;
                        }

                        // BLPOP with timeout - returns after $timeout seconds if no message
                        // This frees the PHP worker periodically, preventing deadlock
                        $result = Redis::blpop($channel, $timeout);

                        if ($result) {
                            // $result = [key, value] from blpop
                            $message = $result[1];
                            $payload = json_decode($message, true);

                            if ($payload && isset($payload['event'], $payload['data'])) {
                                yield $this->formatSSEEvent($payload['event'], $payload['data']);

                                // Stop on terminal events
                                // Tool requests close so CLI can handle tool and reconnect
                                if (in_array($payload['event'], ['completed', 'failed', 'cancelled', 'tool_request'])) {
                                    break;
                                }
                            }
                        }

                        yield $this->formatSSEHeartbeat();
                    }
                } catch (\Exception $e) {
                    Log::error('SSE Redis polling error', [
                        'conversation_id' => $conversation->id,
                        'error' => $e->getMessage(),
                    ]);

                    yield $this->formatSSEEvent('error', [
                        'message' => 'Stream connection error',
                        'conversation_id' => $conversation->id,
                    ]);
                }
            },
            200,
            $this->getSSEHeaders()
        );
    }

    /**
     * Show Conversation
     *
     * Get full conversation details and history.
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @apiResource App\Http\Resources\ConversationResource
     *
     * @apiResourceModel App\Models\Conversation with=agent
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 123"}
     */
    public function show(Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        $conversation->load(['agent', 'conversationMessages.toolCalls', 'conversationMessages.attachments']);

        return new ConversationResource($conversation);
    }

    /**
     * List Conversations
     *
     * Get paginated list of user's conversations.
     *
     * @queryParam agent_id integer Filter by agent ID. Example: 1
     * @queryParam status string Filter by status. Example: active
     * @queryParam per_page integer Items per page. Example: 15
     *
     * @apiResourceCollection App\Http\Resources\ConversationResource
     *
     * @apiResourceModel App\Models\Conversation with=agent paginate=15
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()
            ->conversations()
            ->with('agent')
            ->latest('last_activity_at');

        if ($request->has('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $conversations = $query->paginate($request->input('per_page', 15));

        return ConversationResource::collection($conversations);
    }

    /**
     * Delete Conversation
     *
     * Delete a conversation permanently.
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @response 204 scenario="Success"
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 123"}
     */
    public function destroy(Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(null, 204);
    }

    /**
     * Attach documents to a conversation.
     *
     * Only attaches documents that:
     * - Belong to the user
     * - Have status "ready"
     * - Are not already attached
     *
     * @param  array<int>  $documentIds
     */
    protected function attachDocuments(Conversation $conversation, User $user, array $documentIds): void
    {
        $documents = Document::query()
            ->where('user_id', $user->id)
            ->where('status', DocumentStatus::Ready)
            ->whereIn('id', $documentIds)
            ->get();

        foreach ($documents as $document) {
            // Skip if already attached
            if ($conversation->documents()->where('documents.id', $document->id)->exists()) {
                continue;
            }

            $previewChunks = 2;
            $previewTokens = $document->chunks()
                ->ordered()
                ->limit($previewChunks)
                ->sum('token_count');

            $conversation->documents()->attach($document->id, [
                'preview_chunks' => $previewChunks,
                'preview_tokens' => $previewTokens,
                'attached_at' => now(),
            ]);
        }
    }
}
