<?php

namespace App\Jobs;

use App\DTOs\ChatMessage;
use App\DTOs\Search\SearchQuery;
use App\DTOs\ToolCall;
use App\DTOs\ToolResult;
use App\DTOs\WebFetch\FetchRequest;
use App\Exceptions\SearchException;
use App\Exceptions\WebFetchException;
use App\Models\Conversation;
use App\Models\Todo;
use App\Services\AIBackendManager;
use App\Services\ClientToolRegistry;
use App\Services\ConversationEventBroadcaster;
use App\Services\Search\SearchService;
use App\Services\ToolSchemaRegistry;
use App\Services\ToolService;
use App\Services\WebFetch\WebFetchService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessConversationTurn implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     * Set high to allow for long AI responses.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     * AI calls should not be retried automatically.
     */
    public int $tries = 1;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    // System tools that are executed on the server
    protected const SYSTEM_TOOLS = [
        'todo_add',
        'todo_list',
        'todo_complete',
        'todo_update',
        'todo_delete',
        'todo_clear',
        'web_search',
        'web_fetch',
    ];

    public function __construct(
        public Conversation $conversation
    ) {}

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'conversation:'.$this->conversation->id,
            'user:'.$this->conversation->user_id,
            'agent:'.$this->conversation->agent_id,
        ];
    }

    public function handle(AIBackendManager $aiBackendManager, ToolService $toolService): void
    {
        // Eager load relationships to prevent N+1 queries
        $this->conversation->load(['agent.tools']);

        // Get AI backend and broadcaster early so we can disconnect in finally
        $backend = $aiBackendManager->driver($this->conversation->agent->ai_backend);
        $broadcaster = app(ConversationEventBroadcaster::class);

        try {
            $maxTurns = $this->conversation->getMaxTurns();

            // Check if max turns for this request reached
            if ($this->conversation->getRequestTurnCount() >= $maxTurns) {
                $this->conversation->markAsCompleted();

                return;
            }

            // Increment both turn counts
            $this->conversation->incrementTurn();
            $this->conversation->incrementRequestTurn();

            // Prepare context with turn info
            $context = [
                'messages' => $this->conversation->getMessages(),
                'tools' => $this->getAllToolSchemas(),
                'request_turn' => $this->conversation->getRequestTurnCount(),
                'max_turns' => $this->conversation->getMaxTurns(),
            ];

            Log::info('Tool schemas for AI request', [
                'conversation_id' => $this->conversation->id,
                'tool_count' => count($context['tools']),
                'tool_names' => array_column($context['tools'], 'name'),
            ]);

            // Call AI backend with streaming - broadcast chunks via SSE
            $response = $backend->streamExecute(
                $this->conversation->agent,
                $context,
                function (string $chunk, string $type = 'content') use ($broadcaster) {
                    $broadcaster->textChunk($this->conversation, $chunk, $type);
                }
            );

            // Track tokens
            $this->conversation->addTokens($response->tokensUsed);

            Log::info('AI response received', [
                'conversation_id' => $this->conversation->id,
                'has_content' => ! empty($response->content),
                'content_preview' => substr($response->content, 0, 200),
                'tool_calls_count' => count($response->toolCalls),
                'tool_calls' => array_map(fn (ToolCall $tc) => $tc->toArray(), $response->toolCalls),
                'finish_reason' => $response->finishReason,
            ]);

            // Filter to only valid, known tool calls
            $validToolCalls = $this->filterValidToolCalls($response->toolCalls);

            // Add AI response to conversation (complete message for DB storage and polling)
            $assistantMessage = ChatMessage::assistant(
                $response->content,
                array_map(fn (ToolCall $tc) => $tc->toArray(), $validToolCalls),
                $response->thinking
            );
            $this->conversation->addMessage($assistantMessage->toArray());

            // If no valid tool calls, conversation turn is complete
            if (empty($validToolCalls)) {
                $this->conversation->markAsCompleted();
                app(ConversationEventBroadcaster::class)->completed($this->conversation);

                return;
            }

            // Process tool calls
            $this->processToolCalls($validToolCalls, $toolService);
        } catch (Exception $e) {
            Log::error('Conversation turn failed', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->conversation->update(['status' => 'failed']);
            app(ConversationEventBroadcaster::class)->failed($this->conversation, $e->getMessage());
        } finally {
            // Disconnect with separate error handling - don't let cleanup errors break job completion
            try {
                $backend->disconnect();
            } catch (\Throwable $e) {
                Log::warning('Backend disconnect failed', [
                    'conversation_id' => $this->conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $broadcaster->disconnect();
            } catch (\Throwable $e) {
                Log::warning('Broadcaster disconnect failed', [
                    'conversation_id' => $this->conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Release database connection and force garbage collection
            DB::disconnect();
            gc_collect_cycles();
        }
    }

    /**
     * Process tool calls from the AI response.
     *
     * @param  array<ToolCall>  $toolCalls
     */
    protected function processToolCalls(array $toolCalls, ToolService $toolService): void
    {
        foreach ($toolCalls as $toolCall) {
            if ($this->isBuiltinTool($toolCall->name)) {
                // Pause conversation and request tool execution from CLI
                $toolArray = $toolCall->toArray();
                $this->conversation->update([
                    'status' => 'paused',
                    'waiting_for' => 'tool_result',
                    'pending_tool_request' => $toolArray,
                ]);

                app(ConversationEventBroadcaster::class)->toolRequest($this->conversation, $toolArray);

                return; // Exit - CLI will submit result and trigger next job
            }

            if ($this->isSystemTool($toolCall->name)) {
                // Execute system tool on server
                $result = $this->executeSystemTool($toolCall);

                // Add tool result to conversation
                $toolMessage = ChatMessage::tool($result->output ?? $result->error ?? '', $toolCall->id);
                $this->conversation->addMessage($toolMessage->toArray());
            } else {
                // Execute user tool on server
                $result = $this->executeUserTool($toolCall, $toolService);

                // Add tool result to conversation
                $toolMessage = ChatMessage::tool($result->output ?? $result->error ?? '', $toolCall->id);
                $this->conversation->addMessage($toolMessage->toArray());
            }
        }

        // All tools executed (system/user), dispatch next turn
        self::dispatch($this->conversation);
    }

    protected function isBuiltinTool(string $toolName): bool
    {
        return app(ClientToolRegistry::class)->clientSupports($this->conversation, $toolName);
    }

    protected function isSystemTool(string $toolName): bool
    {
        return in_array($toolName, self::SYSTEM_TOOLS, true);
    }

    /**
     * Check if a tool name is a known user tool for this agent.
     */
    protected function isUserTool(string $toolName): bool
    {
        return $this->conversation->agent->tools()
            ->where('name', $toolName)
            ->exists();
    }

    /**
     * Check if a tool name is known (builtin, system, or user).
     */
    protected function isKnownTool(string $toolName): bool
    {
        if (empty($toolName)) {
            return false;
        }

        return $this->isBuiltinTool($toolName)
            || $this->isSystemTool($toolName)
            || $this->isUserTool($toolName);
    }

    /**
     * Filter tool calls to only include valid, known tools.
     *
     * @param  array<ToolCall>  $toolCalls
     * @return array<ToolCall>
     */
    protected function filterValidToolCalls(array $toolCalls): array
    {
        $validCalls = [];

        foreach ($toolCalls as $toolCall) {
            if ($this->isKnownTool($toolCall->name)) {
                $validCalls[] = $toolCall;
            } else {
                Log::warning('Filtered out invalid/unknown tool call', [
                    'conversation_id' => $this->conversation->id,
                    'tool_name' => $toolCall->name,
                    'tool_id' => $toolCall->id,
                ]);
            }
        }

        return $validCalls;
    }

    protected function executeSystemTool(ToolCall $toolCall): ToolResult
    {
        try {
            return match ($toolCall->name) {
                'todo_add' => $this->todoAdd($toolCall->arguments),
                'todo_list' => $this->todoList(),
                'todo_complete' => $this->todoComplete($toolCall->arguments),
                'todo_update' => $this->todoUpdate($toolCall->arguments),
                'todo_delete' => $this->todoDelete($toolCall->arguments),
                'todo_clear' => $this->todoClear(),
                'web_search' => $this->webSearch($toolCall->arguments),
                'web_fetch' => $this->webFetch($toolCall->arguments),
                default => new ToolResult(
                    success: false,
                    output: '',
                    error: "Unknown system tool: {$toolCall->name}"
                ),
            };
        } catch (Exception $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: "System tool execution failed: {$e->getMessage()}"
            );
        }
    }

    protected function executeUserTool(ToolCall $toolCall, ToolService $toolService): ToolResult
    {
        try {
            $tool = $this->conversation->agent->tools()
                ->where('name', $toolCall->name)
                ->first();

            if (! $tool) {
                return new ToolResult(
                    success: false,
                    output: '',
                    error: "Tool not found: {$toolCall->name}"
                );
            }

            return $toolService->execute($tool, $toolCall->arguments);
        } catch (Exception $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: "User tool execution failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get all tool schemas (client + system + user).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAllToolSchemas(): array
    {
        return app(ToolSchemaRegistry::class)->getToolsForConversation($this->conversation);
    }

    // Todo system tool implementations

    protected function todoAdd(array $args): ToolResult
    {
        $todo = Todo::create([
            'agent_id' => $this->conversation->agent_id,
            'conversation_id' => $this->conversation->id,
            'content' => $args['item'],
            'priority' => $args['priority'] ?? 'medium',
        ]);

        return new ToolResult(
            success: true,
            output: "Added todo #{$todo->id}: {$args['item']} (priority: {$todo->priority})",
            error: null
        );
    }

    protected function todoList(): ToolResult
    {
        $todos = Todo::where('agent_id', $this->conversation->agent_id)
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('created_at')
            ->get();

        if ($todos->isEmpty()) {
            return new ToolResult(
                success: true,
                output: 'No todos found.',
                error: null
            );
        }

        $output = "Todos:\n";
        foreach ($todos as $todo) {
            $status = match ($todo->status) {
                'completed' => '[✓]',
                'in_progress' => '[~]',
                default => '[ ]',
            };
            $output .= "{$status} #{$todo->id}: {$todo->content} ({$todo->priority})\n";
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    protected function todoComplete(array $args): ToolResult
    {
        $id = is_numeric($args['id']) ? (int) $args['id'] : $args['id'];

        $todo = Todo::where('agent_id', $this->conversation->agent_id)
            ->where('id', $id)
            ->first();

        if (! $todo) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Todo not found: {$args['id']}"
            );
        }

        $todo->markAsCompleted();

        return new ToolResult(
            success: true,
            output: "Marked todo #{$todo->id} as complete",
            error: null
        );
    }

    protected function todoUpdate(array $args): ToolResult
    {
        $id = is_numeric($args['id']) ? (int) $args['id'] : $args['id'];

        $todo = Todo::where('agent_id', $this->conversation->agent_id)
            ->where('id', $id)
            ->first();

        if (! $todo) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Todo not found: {$args['id']}"
            );
        }

        $todo->update(['content' => $args['item']]);

        return new ToolResult(
            success: true,
            output: "Updated todo #{$todo->id}",
            error: null
        );
    }

    protected function todoDelete(array $args): ToolResult
    {
        $id = is_numeric($args['id']) ? (int) $args['id'] : $args['id'];

        $todo = Todo::where('agent_id', $this->conversation->agent_id)
            ->where('id', $id)
            ->first();

        if (! $todo) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Todo not found: {$args['id']}"
            );
        }

        $todo->delete();

        return new ToolResult(
            success: true,
            output: "Deleted todo #{$args['id']}",
            error: null
        );
    }

    protected function todoClear(): ToolResult
    {
        $count = Todo::where('agent_id', $this->conversation->agent_id)->count();

        Todo::where('agent_id', $this->conversation->agent_id)->delete();

        return new ToolResult(
            success: true,
            output: "Cleared {$count} todos",
            error: null
        );
    }

    // Web search system tool

    protected function webSearch(array $args): ToolResult
    {
        $query = new SearchQuery(
            query: $args['query'] ?? '',
            maxResults: $args['max_results'] ?? 5,
        );

        if (! $query->isValid()) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Search query cannot be empty'
            );
        }

        try {
            $results = app(SearchService::class)->search($query);

            if ($results->isEmpty()) {
                return new ToolResult(
                    success: true,
                    output: 'No results found for: '.$query->query,
                    error: null
                );
            }

            return new ToolResult(
                success: true,
                output: $results->toJson(),
                error: null
            );
        } catch (SearchException $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Web search failed: '.$e->getMessage()
            );
        }
    }

    // Web fetch system tool

    protected function webFetch(array $args): ToolResult
    {
        $request = new FetchRequest(url: $args['url'] ?? '');

        if (! $request->isValid()) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Invalid or missing URL'
            );
        }

        try {
            $document = app(WebFetchService::class)->fetch($request);

            return new ToolResult(
                success: true,
                output: $document->toJson(),
                error: null
            );
        } catch (WebFetchException $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Web fetch failed: '.$e->getMessage()
            );
        }
    }
}
