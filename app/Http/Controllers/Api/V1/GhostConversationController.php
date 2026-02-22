<?php

namespace App\Http\Controllers\Api\V1;

use App\DTOs\ChatMessage;
use App\Http\Controllers\Concerns\StreamsServerSentEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\GhostConversationRequest;
use App\Models\Agent;
use App\Services\AgenticLoop;
use App\Services\Runtime\InMemoryRuntime;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Ghost Conversations
 *
 * Stateless, ephemeral conversations that are not stored in the database.
 * The client sends the full message history each time.
 *
 * @authenticated
 */
class GhostConversationController extends Controller
{
    use StreamsServerSentEvents;

    public function __construct(
        protected AgenticLoop $agenticLoop
    ) {}

    /**
     * Send Ghost Message (JSON)
     *
     * Run a ghost conversation turn and return the full result as JSON.
     * The agentic loop runs synchronously until completion or a builtin tool request.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     */
    public function send(GhostConversationRequest $request, Agent $agent): JsonResponse
    {
        $runtime = $this->buildRuntime($request, $agent);

        $result = $this->agenticLoop->run(
            $runtime,
            onChunk: function () {},
            onToolExecuting: function () {},
            onToolCompleted: function () {},
            onToolRequest: function () {},
        );

        return response()->json([
            'status' => $result->status,
            'messages' => array_map(
                fn (ChatMessage $m) => $m->toArray(),
                $runtime->getMessages(),
            ),
            'tool_request' => $result->toolRequest,
            'error' => $result->error,
            'stats' => $runtime->getStats(),
        ]);
    }

    /**
     * Send Ghost Message (SSE Stream)
     *
     * Run a ghost conversation turn and stream events via Server-Sent Events.
     * Events: connected, text_chunk, tool_executing, tool_completed, tool_request, completed/failed.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     */
    public function stream(GhostConversationRequest $request, Agent $agent): StreamedResponse
    {
        $runtime = $this->buildRuntime($request, $agent);

        return response()->stream(function () use ($runtime): void {
            $sendSSE = function (string $event, array $data): void {
                echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $sendSSE('connected', [
                'runtime_id' => $runtime->getId(),
                'status' => 'connected',
            ]);

            $result = $this->agenticLoop->run(
                $runtime,
                onChunk: fn (string $chunk, string $type) => $sendSSE('text_chunk', [
                    'chunk' => $chunk,
                    'type' => $type,
                ]),
                onToolExecuting: fn (array $tc) => $sendSSE('tool_executing', [
                    'tool' => [
                        'call_id' => $tc['call_id'] ?? $tc['id'] ?? '',
                        'name' => $tc['name'] ?? '',
                        'arguments' => $tc['arguments'] ?? [],
                    ],
                ]),
                onToolCompleted: fn (string $callId, string $name, bool $success, string $content) => $sendSSE('tool_completed', [
                    'call_id' => $callId,
                    'name' => $name,
                    'success' => $success,
                    'content' => $content,
                ]),
                onToolRequest: fn (array $tr) => $sendSSE('tool_request', [
                    'tool_request' => $tr,
                    'stats' => $runtime->getStats(),
                ]),
            );

            // Send final event
            $sendSSE($result->status, [
                'status' => $result->status,
                'messages' => array_map(
                    fn (ChatMessage $m) => $m->toArray(),
                    $runtime->getMessages(),
                ),
                'tool_request' => $result->toolRequest,
                'error' => $result->error,
                'stats' => $runtime->getStats(),
            ]);
        }, 200, $this->getSSEHeaders());
    }

    /**
     * Build an InMemoryRuntime from the request.
     */
    protected function buildRuntime(GhostConversationRequest $request, Agent $agent): InMemoryRuntime
    {
        $runtime = new InMemoryRuntime(
            agent: $agent,
            user: $request->user(),
            clientToolSchemas: $request->input('client_tool_schemas', []),
            maxTurns: $request->integer('max_turns', 25),
        );

        // Hydrate previous messages for multi-turn
        foreach ($request->input('messages', []) as $msg) {
            $runtime->addMessage(ChatMessage::fromArray($msg));
        }

        // Add new user message if provided
        if ($request->filled('content')) {
            $runtime->addMessage(ChatMessage::user($request->input('content')));
        }

        // Add tool result if resuming from a tool request
        if ($request->filled('tool_result')) {
            $tr = $request->input('tool_result');
            $content = ($tr['output'] ?? '') !== '' ? $tr['output'] : ($tr['error'] ?? '');
            $runtime->addMessage(ChatMessage::tool($content, $tr['call_id']));
        }

        $runtime->resetRequestTurnCount();

        return $runtime;
    }
}
