<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConversationRuntime;
use App\DTOs\AgenticLoopResult;
use App\DTOs\ChatMessage;
use App\DTOs\ToolCall;
use App\DTOs\ToolResult;
use App\Services\Prompts\PromptAssembler;
use App\Services\RAG\RAGPipeline;
use App\Services\Runtime\DatabaseRuntime;
use App\Services\Tools\ConversationMemoryToolHandler;
use App\Services\Tools\DocumentToolHandler;
use App\Services\Tools\TodoToolHandler;
use App\Services\Tools\ToolArgumentValidator;
use App\Services\Tools\WebToolHandler;
use Exception;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgenticLoop
{
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
        'conversation_recall',
        'conversation_memory_status',
    ];

    public function __construct(
        protected AIBackendManager $aiBackendManager,
        protected PromptAssembler $promptAssembler,
        protected ToolSchemaRegistry $toolSchemaRegistry,
        protected ToolArgumentValidator $toolArgumentValidator,
        protected ConversationService $conversationService,
    ) {}

    /**
     * Run the full multi-turn agentic loop until completion, builtin tool request, or failure.
     *
     * @param  callable(string $chunk, string $type): void  $onChunk
     * @param  callable(array $toolCall): void  $onToolExecuting
     * @param  callable(string $callId, string $name, bool $success, string $content): void  $onToolCompleted
     * @param  callable(array $toolRequest): void  $onToolRequest
     */
    public function run(
        ConversationRuntime $runtime,
        callable $onChunk,
        callable $onToolExecuting,
        callable $onToolCompleted,
        callable $onToolRequest,
    ): AgenticLoopResult {
        $totalTurnsExecuted = 0;

        while (true) {
            $result = $this->runSingleTurn(
                $runtime,
                $onChunk,
                $onToolExecuting,
                $onToolCompleted,
                $onToolRequest,
            );

            $totalTurnsExecuted += $result->turnsExecuted;

            // Continue looping only if system tools were executed
            if ($result->status !== 'continue') {
                return new AgenticLoopResult(
                    $result->status,
                    $result->toolRequest,
                    $result->error,
                    $totalTurnsExecuted,
                );
            }
        }
    }

    /**
     * Run a single turn of the agentic loop.
     *
     * Returns 'continue' if system tools were executed and another turn should follow.
     *
     * @param  callable(string $chunk, string $type): void  $onChunk
     * @param  callable(array $toolCall): void  $onToolExecuting
     * @param  callable(string $callId, string $name, bool $success, string $content): void  $onToolCompleted
     * @param  callable(array $toolRequest): void  $onToolRequest
     */
    public function runSingleTurn(
        ConversationRuntime $runtime,
        callable $onChunk,
        callable $onToolExecuting,
        callable $onToolCompleted,
        callable $onToolRequest,
    ): AgenticLoopResult {
        $backend = null;

        try {
            // Check cancellation
            $runtime->refresh();
            if ($runtime->isCancelled()) {
                return AgenticLoopResult::cancelled(0);
            }

            // Get AI backend
            $result = $this->aiBackendManager->forAgent($runtime->getAgent());
            $backend = $result['backend'];
            $modelConfig = $result['config'];

            // Set context limit on first use
            if ($runtime->getContextLimit() === null) {
                $runtime->setContextLimit($backend->getContextLimit());
            }

            Log::info('AI request configuration', [
                'runtime_id' => $runtime->getId(),
                'agent_id' => $runtime->getAgent()->id,
                'config' => $modelConfig->toArray(),
                'warnings' => $modelConfig->validationWarnings,
            ]);

            // Check max turns
            if ($runtime->getRequestTurnCount() >= $runtime->getMaxTurns()) {
                $runtime->markAsCompleted();

                return AgenticLoopResult::maxTurns(0);
            }

            // Increment turns
            $runtime->incrementTurn();
            $runtime->incrementRequestTurn();

            // Assemble prompt
            $systemPrompt = $this->assemblePrompt($runtime);

            // Store snapshot on first turn
            if ($runtime->getTurnCount() === 1) {
                $runtime->storeSnapshot(
                    $systemPrompt,
                    $this->promptAssembler->getLastContext(),
                    $modelConfig->toArray(),
                );
            }

            // Get tool schemas
            $toolSchemas = $this->getToolSchemas($runtime);
            $toolDefinitionTokens = (int) ceil(mb_strlen((string) json_encode($toolSchemas)) / 4);

            // Get filtered messages for AI context
            $filteredMessages = $this->getFilteredMessages($runtime, $toolDefinitionTokens);

            $context = [
                'messages' => $filteredMessages,
                'tools' => $toolSchemas,
                'request_turn' => $runtime->getRequestTurnCount(),
                'max_turns' => $runtime->getMaxTurns(),
                'system_prompt' => $systemPrompt,
            ];

            Log::info('Tool schemas for AI request', [
                'runtime_id' => $runtime->getId(),
                'tool_count' => count($context['tools']),
                'tool_names' => array_column($context['tools'], 'name'),
            ]);

            // Stream AI response
            $response = $backend->streamExecute(
                $runtime->getAgent(),
                $context,
                $onChunk,
            );

            // Track tokens
            $promptTokens = $response->metadata['prompt_eval_count'] ?? 0;
            $completionTokens = $response->metadata['eval_count'] ?? 0;
            $runtime->addTokenUsage($promptTokens, $completionTokens);

            Log::info('AI response received', [
                'runtime_id' => $runtime->getId(),
                'has_content' => ! empty($response->content),
                'content_preview' => substr($response->content, 0, 200),
                'tool_calls_count' => count($response->toolCalls),
                'tool_calls' => array_map(fn (ToolCall $tc) => $tc->toArray(), $response->toolCalls),
                'finish_reason' => $response->finishReason,
            ]);

            // Filter valid tool calls
            $validToolCalls = $this->filterValidToolCalls($runtime, $response->toolCalls, $toolSchemas);

            // Add assistant message
            $assistantMessage = ChatMessage::assistant(
                $response->content,
                array_map(fn (ToolCall $tc) => $tc->toArray(), $validToolCalls),
                $response->thinking,
            )->withTokenCount($completionTokens);
            $runtime->addMessage($assistantMessage);

            // No tool calls → completed
            if (empty($validToolCalls)) {
                $runtime->markAsCompleted();

                return AgenticLoopResult::completed(1);
            }

            // Process tool calls
            return $this->processToolCalls(
                $runtime,
                $validToolCalls,
                $onToolExecuting,
                $onToolCompleted,
                $onToolRequest,
            );
        } catch (Exception $e) {
            Log::error('Agentic loop turn failed', [
                'runtime_id' => $runtime->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $runtime->markAsFailed();

            return AgenticLoopResult::failed($e->getMessage(), 1);
        } finally {
            if ($backend) {
                try {
                    $backend->disconnect();
                } catch (Throwable $e) {
                    Log::warning('Backend disconnect failed', [
                        'runtime_id' => $runtime->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Process tool calls from the AI response.
     *
     * Returns 'continue' if all tools were system tools (caller should loop).
     * Returns 'waiting_for_tool' if a builtin tool needs client execution.
     *
     * @param  array<ToolCall>  $toolCalls
     * @param  callable(array $toolCall): void  $onToolExecuting
     * @param  callable(string $callId, string $name, bool $success, string $content): void  $onToolCompleted
     * @param  callable(array $toolRequest): void  $onToolRequest
     */
    protected function processToolCalls(
        ConversationRuntime $runtime,
        array $toolCalls,
        callable $onToolExecuting,
        callable $onToolCompleted,
        callable $onToolRequest,
    ): AgenticLoopResult {
        foreach ($toolCalls as $toolCall) {
            // Check cancellation before each tool
            $runtime->refresh();
            if ($runtime->isCancelled()) {
                Log::info('Tool processing cancelled', [
                    'runtime_id' => $runtime->getId(),
                    'pending_tool' => $toolCall->name,
                ]);

                return AgenticLoopResult::cancelled(1);
            }

            // Builtin tool → pause and wait for client
            if ($this->isBuiltinTool($runtime, $toolCall->name)) {
                $toolArray = $toolCall->toArray();
                $runtime->setPendingToolRequest($toolArray);
                $onToolRequest($toolArray);

                return AgenticLoopResult::waitingForTool($toolArray, 1);
            }

            // System tool → execute on server
            $onToolExecuting($toolCall->toArray());

            $result = $this->executeSystemTool($runtime, $toolCall);
            $resultContent = $result->output ?? $result->error ?? '';

            $toolMessage = ChatMessage::tool($resultContent, $toolCall->id, $toolCall->name);
            $runtime->addMessage($toolMessage);

            $onToolCompleted($toolCall->id, $toolCall->name, $result->success, $resultContent);
        }

        // Check cancellation before next turn
        $runtime->refresh();
        if ($runtime->isCancelled()) {
            Log::info('Next turn dispatch cancelled', [
                'runtime_id' => $runtime->getId(),
            ]);

            return AgenticLoopResult::cancelled(1);
        }

        // All system tools executed → signal to continue
        return AgenticLoopResult::continue(1);
    }

    /**
     * Assemble the system prompt for the runtime.
     */
    protected function assemblePrompt(ConversationRuntime $runtime): string
    {
        if ($runtime instanceof DatabaseRuntime) {
            return $this->promptAssembler->assemble(
                $runtime->getAgent(),
                $runtime->getConversation(),
            );
        }

        return $this->promptAssembler->assemble(
            $runtime->getAgent(),
            null,
            [
                'conversation_id' => $runtime->getId(),
                'message_count' => $runtime->getMessageCount(),
                'user_name' => $runtime->getUser()?->name,
            ],
        );
    }

    /**
     * Get tool schemas for the runtime.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getToolSchemas(ConversationRuntime $runtime): array
    {
        if ($runtime instanceof DatabaseRuntime) {
            return $this->toolSchemaRegistry->getToolsForConversation(
                $runtime->getConversation(),
            );
        }

        return $this->toolSchemaRegistry->getToolsForRuntime($runtime);
    }

    /**
     * Get filtered messages for AI context.
     *
     * @return array<ChatMessage>
     */
    protected function getFilteredMessages(ConversationRuntime $runtime, int $toolDefinitionTokens): array
    {
        if ($runtime instanceof DatabaseRuntime) {
            return $this->conversationService->getMessagesForAI(
                conversation: $runtime->getConversation(),
                maxOutputTokens: 4096,
                toolDefinitionTokens: $toolDefinitionTokens,
            );
        }

        // In-memory: return messages directly (no context filtering)
        return $runtime->getMessages();
    }

    /**
     * Check if a tool is a builtin tool (executed by the client).
     */
    protected function isBuiltinTool(ConversationRuntime $runtime, string $toolName): bool
    {
        $clientSchemas = $runtime->getClientToolSchemas();
        $clientToolNames = array_column($clientSchemas, 'name');

        return in_array($toolName, $clientToolNames, true);
    }

    /**
     * Check if a tool is a system tool (executed on the server).
     */
    protected function isSystemTool(string $toolName): bool
    {
        return in_array($toolName, self::SYSTEM_TOOLS, true);
    }

    /**
     * Check if a tool name is known (builtin or system).
     */
    protected function isKnownTool(ConversationRuntime $runtime, string $toolName): bool
    {
        if (empty($toolName)) {
            return false;
        }

        return $this->isBuiltinTool($runtime, $toolName)
            || $this->isSystemTool($toolName);
    }

    /**
     * Filter tool calls to only include valid, known tools with valid arguments.
     *
     * @param  array<ToolCall>  $toolCalls
     * @param  array<int, array<string, mixed>>  $schemas
     * @return array<ToolCall>
     */
    protected function filterValidToolCalls(
        ConversationRuntime $runtime,
        array $toolCalls,
        array $schemas,
    ): array {
        $validCalls = [];

        foreach ($toolCalls as $toolCall) {
            if (! $this->isKnownTool($runtime, $toolCall->name)) {
                Log::warning('Filtered out unknown tool call', [
                    'runtime_id' => $runtime->getId(),
                    'tool_name' => $toolCall->name,
                    'tool_id' => $toolCall->id,
                ]);

                continue;
            }

            $schema = $this->toolArgumentValidator->findSchema($toolCall->name, $schemas);
            if ($schema) {
                $validation = $this->toolArgumentValidator->validate($toolCall, $schema);
                if (! $validation['valid']) {
                    Log::warning('Filtered out tool call with invalid arguments', [
                        'runtime_id' => $runtime->getId(),
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

    /**
     * Execute a system tool on the server.
     */
    protected function executeSystemTool(ConversationRuntime $runtime, ToolCall $toolCall): ToolResult
    {
        try {
            $name = $toolCall->name;

            // Non-persistent runtimes can't use DB-dependent tools
            if (! $runtime->isPersistent()) {
                if (str_starts_with($name, 'todo_') || str_starts_with($name, 'document_') || str_starts_with($name, 'conversation_')) {
                    return new ToolResult(
                        success: false,
                        output: '',
                        error: 'This tool is not available in ghost mode',
                    );
                }
            }

            if (str_starts_with($name, 'todo_')) {
                $conversation = ($runtime instanceof DatabaseRuntime) ? $runtime->getConversation() : null;
                if (! $conversation) {
                    return new ToolResult(success: false, output: '', error: 'Todo tools require persistent conversation');
                }

                return (new TodoToolHandler($conversation))->execute($name, $toolCall->arguments);
            }

            if (str_starts_with($name, 'web_')) {
                return app(WebToolHandler::class)->execute($name, $toolCall->arguments);
            }

            if (str_starts_with($name, 'document_')) {
                $conversation = ($runtime instanceof DatabaseRuntime) ? $runtime->getConversation() : null;
                if (! $conversation) {
                    return new ToolResult(success: false, output: '', error: 'Document tools require persistent conversation');
                }

                return (new DocumentToolHandler($conversation, app(RAGPipeline::class)))
                    ->execute($name, $toolCall->arguments);
            }

            if (str_starts_with($name, 'conversation_')) {
                $conversation = ($runtime instanceof DatabaseRuntime) ? $runtime->getConversation() : null;
                if (! $conversation) {
                    return new ToolResult(success: false, output: '', error: 'Memory tools require persistent conversation');
                }

                return (new ConversationMemoryToolHandler(
                    $conversation,
                    app(\App\Services\Embedding\EmbeddingService::class),
                ))->execute($name, $toolCall->arguments);
            }

            return new ToolResult(
                success: false,
                output: '',
                error: "Unknown system tool: {$name}",
            );
        } catch (Exception $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: "System tool execution failed: {$e->getMessage()}",
            );
        }
    }
}
