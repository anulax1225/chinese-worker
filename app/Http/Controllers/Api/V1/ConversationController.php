<?php

namespace App\Http\Controllers\Api\V1;

use App\DTOs\ToolResult;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Agent;
use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Conversation Management
 *
 * APIs for managing AI agent conversations
 */
class ConversationController extends Controller
{
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
     * @response 201 {
     *   "conversation": {
     *     "id": 123,
     *     "agent_id": 1,
     *     "user_id": 1,
     *     "status": "active",
     *     "messages": [],
     *     "turn_count": 0,
     *     "total_tokens": 0,
     *     "created_at": "2026-01-27T10:00:00.000000Z"
     *   }
     * }
     */
    public function store(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $conversation = Conversation::create([
            'agent_id' => $agent->id,
            'user_id' => $request->user()->id,
            'status' => 'active',
            'messages' => [],
            'metadata' => $request->input('metadata', []),
            'turn_count' => 0,
            'total_tokens' => 0,
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
     *
     * @response 202 {
     *   "status": "processing",
     *   "conversation_id": 123,
     *   "check_url": "/api/v1/conversations/123/status"
     * }
     */
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $request->validate([
            'content' => ['required', 'string'],
            'images' => ['nullable', 'array'],
        ]);

        // Process message asynchronously (could use a queue job here)
        $state = $this->conversationService->processMessage(
            $conversation,
            $request->input('content'),
            $request->input('images')
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
     * @response 200 {
     *   "status": "completed",
     *   "conversation_id": 123,
     *   "messages": [
     *     {"role": "assistant", "content": "I found 2 files in /tmp..."}
     *   ],
     *   "stats": {
     *     "turns": 5,
     *     "tokens": 450
     *   }
     * }
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

            if ($lastMessage && $lastMessage['role'] === 'assistant') {
                $response['messages'] = [$lastMessage];
            }
        }

        return response()->json($response);
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
     * @response 200 {
     *   "status": "processing",
     *   "conversation_id": 123
     * }
     */
    public function submitToolResult(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $request->validate([
            'call_id' => ['required', 'string'],
            'success' => ['required', 'boolean'],
            'output' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
        ]);

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
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @response 200 {
     *   "event": "tool_request",
     *   "data": {"status": "waiting_for_tool", "tool_request": {...}}
     * }
     */
    public function stream(Conversation $conversation): StreamedResponse
    {
        $this->authorize('view', $conversation);

        return response()->stream(
            function () use ($conversation) {
                // Disable output buffering for real-time streaming
                if (ob_get_level()) {
                    ob_end_clean();
                }

                // 2KB padding for nginx buffering
                echo ':'.str_repeat(' ', 2048)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                // Send initial connected event
                $this->sendSSEEvent('connected', [
                    'conversation_id' => $conversation->id,
                    'status' => 'connected',
                ]);

                // Check current conversation state and send if already terminal
                // This handles the race condition where job finishes before SSE connects
                $conversation->refresh();

                if ($conversation->isWaitingForTool()) {
                    $this->sendSSEEvent('tool_request', [
                        'status' => 'waiting_for_tool',
                        'conversation_id' => $conversation->id,
                        'tool_request' => $conversation->pending_tool_request,
                        'submit_url' => "/api/v1/conversations/{$conversation->id}/tool-results",
                        'stats' => [
                            'turns' => $conversation->turn_count,
                            'tokens' => $conversation->total_tokens,
                        ],
                    ]);

                    return; // Don't subscribe, already have result
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
                    if ($lastMessage && $lastMessage['role'] === 'assistant') {
                        $data['messages'] = [$lastMessage];
                    }
                    $this->sendSSEEvent('completed', $data);

                    return; // Don't subscribe, already completed
                }

                if ($conversation->status === 'failed') {
                    $this->sendSSEEvent('failed', [
                        'status' => 'failed',
                        'conversation_id' => $conversation->id,
                        'error' => 'Conversation failed',
                        'stats' => [
                            'turns' => $conversation->turn_count,
                            'tokens' => $conversation->total_tokens,
                        ],
                    ]);

                    return; // Don't subscribe, already failed
                }

                // Subscribe to Redis channel for this conversation
                $channel = "conversation:{$conversation->id}:events";

                // Release database connection before blocking - we only need Redis from here
                // This prevents the SSE endpoint from holding DB connections while waiting for events
                DB::disconnect();

                try {
                    $redis = Redis::connection('default');

                    // Use pub/sub - this blocks until events arrive
                    $redis->subscribe([$channel], function ($message, $channel) {
                        $payload = json_decode($message, true);

                        if ($payload && isset($payload['event'], $payload['data'])) {
                            $this->sendSSEEvent($payload['event'], $payload['data']);

                            // Close connection after terminal events or tool requests
                            // Tool requests close so CLI can handle tool and reconnect
                            if (in_array($payload['event'], ['completed', 'failed', 'tool_request'])) {
                                return false; // Stop subscription
                            }
                        }

                        return true; // Continue listening
                    });
                } catch (\Exception $e) {
                    $this->sendSSEEvent('error', [
                        'message' => 'Stream connection error',
                        'conversation_id' => $conversation->id,
                    ]);
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no', // Disable nginx buffering
            ]
        );
    }

    /**
     * Send an SSE event to the stream.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sendSSEEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Show Conversation
     *
     * Get full conversation details and history.
     *
     * @urlParam conversation integer required The conversation ID. Example: 123
     *
     * @response 200 {
     *   "conversation": {
     *     "id": 123,
     *     "agent": {"id": 1, "name": "Code Assistant"},
     *     "status": "active",
     *     "messages": [...],
     *     "turn_count": 5,
     *     "total_tokens": 1250,
     *     "started_at": "2026-01-27T10:00:00.000000Z",
     *     "last_activity_at": "2026-01-27T10:30:00.000000Z"
     *   }
     * }
     */
    public function show(Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        $conversation->load('agent');

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
     * @response 200 {
     *   "data": [...],
     *   "links": {...},
     *   "meta": {...}
     * }
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
     * @response 204
     */
    public function destroy(Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(null, 204);
    }
}
