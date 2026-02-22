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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
     * Run a stateless ghost conversation turn and return the full result as JSON.
     * The agentic loop runs synchronously until completion or a builtin tool request.
     * Nothing is stored in the database — the client must send the full message history each time.
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam content string The user message content. Required unless resuming with tool_result. Example: What files are in /tmp?
     * @bodyParam messages array Previous conversation messages for multi-turn context.
     * @bodyParam messages[].role string required The message role. Example: user
     * @bodyParam messages[].content string required The message content. Example: Hello
     * @bodyParam messages[].tool_calls array Tool calls made by the assistant.
     * @bodyParam messages[].tool_call_id string The tool call ID (for tool result messages). Example: call_abc123
     * @bodyParam messages[].thinking string Extended thinking content.
     * @bodyParam messages[].name string Tool function name (for tool result messages). Example: web_search
     * @bodyParam tool_result object Result of a builtin tool execution, for resuming after a tool_request.
     * @bodyParam tool_result.call_id string required The tool call ID being responded to. Example: call_abc123
     * @bodyParam tool_result.success boolean required Whether tool execution succeeded. Example: true
     * @bodyParam tool_result.output string The tool output (if successful). Example: file1.txt\nfile2.txt
     * @bodyParam tool_result.error string The error message (if failed). Example: Command not found
     * @bodyParam client_tool_schemas array Client-side tool schemas for builtin tools the AI can request.
     * @bodyParam client_tool_schemas[].name string required The tool name. Example: bash
     * @bodyParam client_tool_schemas[].description string required The tool description. Example: Run a shell command
     * @bodyParam client_tool_schemas[].parameters object required JSON Schema for tool parameters. Example: {"type": "object"}
     * @bodyParam max_turns integer Maximum number of agentic loop turns (1-50). Example: 25
     * @bodyParam context object Key-value pairs of context variables for system prompt template rendering. These are merged into the runtime context passed to the PromptAssembler. Example: {"project_name": "Acme", "language": "en"}
     *
     * @response 200 scenario="Completed" {"status": "completed", "messages": [{"role": "user", "content": "What files are in /tmp?", "tool_calls": null, "tool_call_id": null, "images": null, "thinking": null, "name": null, "token_count": null, "counted_at": null}, {"role": "assistant", "content": "I found 3 files in /tmp: file1.txt, file2.txt, notes.md", "tool_calls": null, "tool_call_id": null, "images": null, "thinking": null, "name": null, "token_count": 15, "counted_at": null}], "tool_request": null, "error": null, "stats": {"turns": 1, "tokens": 150, "prompt_tokens": 100, "completion_tokens": 50}}
     * @response 200 scenario="Waiting for Tool" {"status": "waiting_for_tool", "messages": [{"role": "user", "content": "List files in /tmp", "tool_calls": null, "tool_call_id": null, "images": null, "thinking": null, "name": null, "token_count": null, "counted_at": null}, {"role": "assistant", "content": "", "tool_calls": [{"call_id": "call_abc123", "name": "bash", "arguments": {"command": "ls /tmp"}}], "tool_call_id": null, "images": null, "thinking": null, "name": null, "token_count": 10, "counted_at": null}], "tool_request": {"call_id": "call_abc123", "name": "bash", "arguments": {"command": "ls /tmp"}}, "error": null, "stats": {"turns": 1, "tokens": 120, "prompt_tokens": 80, "completion_tokens": 40}}
     * @response 200 scenario="Failed" {"status": "failed", "messages": [{"role": "user", "content": "Hello", "tool_calls": null, "tool_call_id": null, "images": null, "thinking": null, "name": null, "token_count": null, "counted_at": null}], "tool_request": null, "error": "AI backend connection refused", "stats": {"turns": 0, "tokens": 0, "prompt_tokens": 0, "completion_tokens": 0}}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Agent Not Found" {"message": "No query results for model [App\\Models\\Agent] 1"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"content": ["The content field is required when tool result is not present."]}}
     */
    public function send(GhostConversationRequest $request, Agent $agent): JsonResponse
    {
        $runtime = $this->buildRuntime($request, $agent);

        try {
            $result = $this->agenticLoop->run(
                $runtime,
                onChunk: function () {},
                onToolExecuting: function () {},
                onToolCompleted: function () {},
                onToolRequest: function () {},
            );
        } catch (Throwable $e) {
            Log::error('Ghost conversation failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'failed',
                'messages' => array_map(
                    fn (ChatMessage $m) => $m->toArray(),
                    $runtime->getMessages(),
                ),
                'tool_request' => null,
                'error' => $e->getMessage(),
                'stats' => $runtime->getStats(),
            ], 500);
        }

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
     * Run a stateless ghost conversation turn and stream events via Server-Sent Events.
     * Nothing is stored in the database — the client must send the full message history each time.
     *
     * SSE events emitted in order:
     * - `connected` — Stream established, includes runtime_id
     * - `text_chunk` — AI response token (chunk + type)
     * - `tool_executing` — Server-side tool starting (call_id, name, arguments)
     * - `tool_completed` — Server-side tool finished (call_id, name, success, content)
     * - `tool_request` — Builtin tool needs client execution (tool_request, stats)
     * - `completed` / `failed` / `max_turns` — Final event with full messages, stats, and error
     *
     * @urlParam agent integer required The agent ID. Example: 1
     *
     * @bodyParam content string The user message content. Required unless resuming with tool_result. Example: What files are in /tmp?
     * @bodyParam messages array Previous conversation messages for multi-turn context.
     * @bodyParam messages[].role string required The message role. Example: user
     * @bodyParam messages[].content string required The message content. Example: Hello
     * @bodyParam messages[].tool_calls array Tool calls made by the assistant.
     * @bodyParam messages[].tool_call_id string The tool call ID (for tool result messages). Example: call_abc123
     * @bodyParam messages[].thinking string Extended thinking content.
     * @bodyParam messages[].name string Tool function name (for tool result messages). Example: web_search
     * @bodyParam tool_result object Result of a builtin tool execution, for resuming after a tool_request.
     * @bodyParam tool_result.call_id string required The tool call ID being responded to. Example: call_abc123
     * @bodyParam tool_result.success boolean required Whether tool execution succeeded. Example: true
     * @bodyParam tool_result.output string The tool output (if successful). Example: file1.txt\nfile2.txt
     * @bodyParam tool_result.error string The error message (if failed). Example: Command not found
     * @bodyParam client_tool_schemas array Client-side tool schemas for builtin tools the AI can request.
     * @bodyParam client_tool_schemas[].name string required The tool name. Example: bash
     * @bodyParam client_tool_schemas[].description string required The tool description. Example: Run a shell command
     * @bodyParam client_tool_schemas[].parameters object required JSON Schema for tool parameters. Example: {"type": "object"}
     * @bodyParam max_turns integer Maximum number of agentic loop turns (1-50). Example: 25
     * @bodyParam context object Key-value pairs of context variables for system prompt template rendering. These are merged into the runtime context passed to the PromptAssembler. Example: {"project_name": "Acme", "language": "en"}
     *
     * @response 200 scenario="SSE Stream" {"event": "connected", "data": {"runtime_id": "ghost_550e8400-e29b-41d4-a716-446655440000", "status": "connected"}}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Agent Not Found" {"message": "No query results for model [App\\Models\\Agent] 1"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"content": ["The content field is required when tool result is not present."]}}
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

            try {
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
            } catch (Throwable $e) {
                Log::error('Ghost conversation stream failed', [
                    'agent_id' => $runtime->getAgent()->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $sendSSE('failed', [
                    'status' => 'failed',
                    'messages' => array_map(
                        fn (ChatMessage $m) => $m->toArray(),
                        $runtime->getMessages(),
                    ),
                    'tool_request' => null,
                    'error' => $e->getMessage(),
                    'stats' => $runtime->getStats(),
                ]);
            }
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
            contextVariables: $request->input('context', []),
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
