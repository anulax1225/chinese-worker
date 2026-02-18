<?php

namespace App\Jobs;

use App\DTOs\ChatMessage;
use App\DTOs\ToolCall;
use App\DTOs\ToolResult;
use App\Models\Conversation;
use App\Services\AIBackendManager;
use App\Services\ClientToolRegistry;
use App\Services\ConversationEventBroadcaster;
use App\Services\ConversationService;
use App\Services\Prompts\PromptAssembler;
use App\Services\RAG\RAGPipeline;
use App\Services\Tools\ConversationMemoryToolHandler;
use App\Services\Tools\DocumentToolHandler;
use App\Services\Tools\TodoToolHandler;
use App\Services\Tools\ToolArgumentValidator;
use App\Services\Tools\WebToolHandler;
use App\Services\ToolSchemaRegistry;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessConversationTurn implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     * Set high to allow for long AI responses.
     */
    public int $timeout = 12000;

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
        'document_list',
        'document_info',
        'document_get_chunks',
        'document_read_file',
        'document_search',
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

    public function handle(AIBackendManager $aiBackendManager, ConversationService $conversationService): void
    {
        // Check if cancelled before starting
        $this->conversation->refresh();
        if ($this->conversation->isCancelled()) {
            Log::info('Job skipped - conversation cancelled', [
                'conversation_id' => $this->conversation->id,
            ]);

            return;
        }

        // Eager load relationships to prevent N+1 queries
        $this->conversation->load(['agent']);

        // Get AI backend configured for this agent with normalized config
        $result = $aiBackendManager->forAgent($this->conversation->agent);
        $backend = $result['backend'];
        $modelConfig = $result['config'];

        $broadcaster = app(ConversationEventBroadcaster::class);

        try {
            // Log model configuration for debugging
            Log::info('AI request configuration', [
                'conversation_id' => $this->conversation->id,
                'agent_id' => $this->conversation->agent_id,
                'config' => $modelConfig->toArray(),
                'warnings' => $modelConfig->validationWarnings,
            ]);

            $maxTurns = $this->conversation->getMaxTurns();

            // Check if max turns for this request reached
            if ($this->conversation->getRequestTurnCount() >= $maxTurns) {
                $this->conversation->markAsCompleted();

                return;
            }

            // Increment both turn counts
            $this->conversation->incrementTurn();
            $this->conversation->incrementRequestTurn();

            // Assemble system prompt using the new pipeline
            $assembler = app(PromptAssembler::class);
            $systemPrompt = $assembler->assemble($this->conversation->agent, $this->conversation);

            // Store snapshot on first turn for debugging
            if ($this->conversation->turn_count === 1) {
                $this->conversation->update([
                    'system_prompt_snapshot' => $systemPrompt,
                    'prompt_context_snapshot' => $assembler->getLastContext(),
                    'model_config_snapshot' => $modelConfig->toArray(),
                ]);
            }

            // Prepare context with turn info and assembled prompt
            $toolSchemas = $this->getAllToolSchemas();
            $toolDefinitionTokens = (int) ceil(mb_strlen((string) json_encode($toolSchemas)) / 4);

            $filteredMessages = $conversationService->getMessagesForAI(
                conversation: $this->conversation,
                maxOutputTokens: 4096,
                toolDefinitionTokens: $toolDefinitionTokens,
            );

            $context = [
                'messages' => $filteredMessages,
                'tools' => $toolSchemas,
                'request_turn' => $this->conversation->getRequestTurnCount(),
                'max_turns' => $this->conversation->getMaxTurns(),
                'system_prompt' => $systemPrompt,
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

            // Track tokens with prompt/completion breakdown
            $promptTokens = $response->metadata['prompt_eval_count'] ?? 0;
            $completionTokens = $response->metadata['eval_count'] ?? 0;
            $this->conversation->addTokenUsage($promptTokens, $completionTokens);

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

            // Add AI response to conversation with token count
            $assistantMessage = ChatMessage::assistant(
                $response->content,
                array_map(fn (ToolCall $tc) => $tc->toArray(), $validToolCalls),
                $response->thinking
            )->withTokenCount($completionTokens);
            $this->conversation->addMessage($assistantMessage);

            // If no valid tool calls, conversation turn is complete
            if (empty($validToolCalls)) {
                $this->conversation->markAsCompleted();
                app(ConversationEventBroadcaster::class)->completed($this->conversation);

                return;
            }

            // Process tool calls
            $this->processToolCalls($validToolCalls);
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
            } catch (Throwable $e) {
                Log::warning('Backend disconnect failed', [
                    'conversation_id' => $this->conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $broadcaster->disconnect();
            } catch (Throwable $e) {
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
    protected function processToolCalls(array $toolCalls): void
    {
        $broadcaster = app(ConversationEventBroadcaster::class);

        foreach ($toolCalls as $toolCall) {
            // Check for cancellation before each tool execution
            $this->conversation->refresh();
            if ($this->conversation->isCancelled()) {
                Log::info('Tool processing cancelled', [
                    'conversation_id' => $this->conversation->id,
                    'pending_tool' => $toolCall->name,
                ]);

                return;
            }

            if ($this->isBuiltinTool($toolCall->name)) {
                // Pause conversation and request tool execution from CLI
                $toolArray = $toolCall->toArray();
                $this->conversation->update([
                    'status' => 'paused',
                    'waiting_for' => 'tool_result',
                    'pending_tool_request' => $toolArray,
                ]);

                $broadcaster->toolRequest($this->conversation, $toolArray);

                return; // Exit - CLI will submit result and trigger next job
            }

            // Broadcast that tool is executing
            $broadcaster->toolExecuting($this->conversation, $toolCall->toArray());

            // Execute system tool on server
            $result = $this->executeSystemTool($toolCall);
            $resultContent = $result->output ?? $result->error ?? '';

            // Add tool result to conversation
            $toolMessage = ChatMessage::tool($resultContent, $toolCall->id, $toolCall->name);
            $this->conversation->addMessage($toolMessage);

            // Broadcast tool completed with result content
            $broadcaster->toolCompleted($this->conversation, $toolCall->id, $toolCall->name, $result->success, $resultContent);
        }

        // Check for cancellation before dispatching next turn
        $this->conversation->refresh();
        if ($this->conversation->isCancelled()) {
            Log::info('Next turn dispatch cancelled', [
                'conversation_id' => $this->conversation->id,
            ]);

            return;
        }

        // All system tools executed, dispatch next turn
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
     * Check if a tool name is known (builtin or system).
     */
    protected function isKnownTool(string $toolName): bool
    {
        if (empty($toolName)) {
            return false;
        }

        return $this->isBuiltinTool($toolName)
            || $this->isSystemTool($toolName);
    }

    /**
     * Filter tool calls to only include valid, known tools with valid arguments.
     *
     * @param  array<ToolCall>  $toolCalls
     * @return array<ToolCall>
     */
    protected function filterValidToolCalls(array $toolCalls): array
    {
        $validCalls = [];
        $validator = app(ToolArgumentValidator::class);
        $schemas = $this->getAllToolSchemas();

        foreach ($toolCalls as $toolCall) {
            if (! $this->isKnownTool($toolCall->name)) {
                Log::warning('Filtered out unknown tool call', [
                    'conversation_id' => $this->conversation->id,
                    'tool_name' => $toolCall->name,
                    'tool_id' => $toolCall->id,
                ]);

                continue;
            }

            // Validate arguments against schema
            $schema = $validator->findSchema($toolCall->name, $schemas);
            if ($schema) {
                $validation = $validator->validate($toolCall, $schema);
                if (! $validation['valid']) {
                    Log::warning('Filtered out tool call with invalid arguments', [
                        'conversation_id' => $this->conversation->id,
                        'tool_name' => $toolCall->name,
                        'tool_id' => $toolCall->id,
                        'errors' => $validation['errors'],
                    ]);

                    continue;
                }
            }

            $validCalls[] = $toolCall;
        }

        return $validCalls;
    }

    protected function executeSystemTool(ToolCall $toolCall): ToolResult
    {
        try {
            // Route to appropriate handler based on tool prefix
            if (str_starts_with($toolCall->name, 'todo_')) {
                $handler = new TodoToolHandler($this->conversation);

                return $handler->execute($toolCall->name, $toolCall->arguments);
            }

            if (str_starts_with($toolCall->name, 'web_')) {
                $handler = app(WebToolHandler::class);

                return $handler->execute($toolCall->name, $toolCall->arguments);
            }

            if (str_starts_with($toolCall->name, 'document_')) {
                $handler = new DocumentToolHandler(
                    $this->conversation,
                    app(RAGPipeline::class),
                );

                return $handler->execute($toolCall->name, $toolCall->arguments);
            }

            if (str_starts_with($toolCall->name, 'conversation_')) {
                $handler = new ConversationMemoryToolHandler(
                    $this->conversation,
                    app(\App\Services\RAG\EmbeddingService::class),
                );

                return $handler->execute($toolCall->name, $toolCall->arguments);
            }

            return new ToolResult(
                success: false,
                output: '',
                error: "Unknown system tool: {$toolCall->name}"
            );
        } catch (Exception $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: "System tool execution failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get all tool schemas (client + system).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAllToolSchemas(): array
    {
        return app(ToolSchemaRegistry::class)->getToolsForConversation($this->conversation);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Conversation turn job failed', [
            'conversation_id' => $this->conversation->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->conversation->update(['status' => 'failed']);
        app(ConversationEventBroadcaster::class)->failed(
            $this->conversation,
            $exception?->getMessage() ?? 'Unknown error'
        );
    }
}
