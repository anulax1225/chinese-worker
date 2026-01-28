<?php

namespace App\Jobs;

use App\DTOs\ChatMessage;
use App\DTOs\ToolCall;
use App\DTOs\ToolResult;
use App\Models\Conversation;
use App\Services\AIBackendManager;
use App\Services\ToolService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessConversationTurn implements ShouldQueue
{
    use Queueable;

    // Builtin tools that must be executed on the CLI
    protected const BUILTIN_TOOLS = [
        'bash',
        'read',
        'write',
        'edit',
        'glob',
        'grep',
    ];

    // System tools that are executed on the server
    protected const SYSTEM_TOOLS = [
        'todo_add',
        'todo_list',
        'todo_complete',
        'todo_update',
        'todo_delete',
        'todo_clear',
    ];

    public function __construct(
        public Conversation $conversation
    ) {}

    public function handle(AIBackendManager $aiBackendManager, ToolService $toolService): void
    {
        try {
            // Check if max turns reached
            if ($this->conversation->turn_count >= 25) {
                $this->conversation->markAsCompleted();
                Log::info('Conversation reached max turns', ['conversation_id' => $this->conversation->id]);

                return;
            }

            // Increment turn
            $this->conversation->incrementTurn();

            // Get AI backend
            $backend = $aiBackendManager->driver($this->conversation->agent->ai_backend);

            // Prepare context
            $context = [
                'messages' => $this->conversation->getMessages(),
                'tools' => $this->getAllToolSchemas(),
            ];

            // Call AI backend
            $response = $backend->execute($this->conversation->agent, $context);

            // Track tokens
            $this->conversation->addTokens($response->tokensUsed);

            // Add AI response to conversation
            $assistantMessage = ChatMessage::assistant(
                $response->content,
                array_map(fn (ToolCall $tc) => $tc->toArray(), $response->toolCalls),
                $response->thinking
            );
            $this->conversation->addMessage($assistantMessage->toArray());

            // If no tool calls, conversation turn is complete
            if (! $response->hasToolCalls()) {
                $this->conversation->markAsCompleted();
                Log::info('Conversation completed', ['conversation_id' => $this->conversation->id]);

                return;
            }

            // Process tool calls
            $this->processToolCalls($response->toolCalls, $toolService);
        } catch (Exception $e) {
            Log::error('Conversation turn failed', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->conversation->update(['status' => 'failed']);
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
                $this->conversation->update([
                    'status' => 'paused',
                    'waiting_for' => 'tool_result',
                    'pending_tool_request' => $toolCall->toArray(),
                ]);

                Log::info('Waiting for builtin tool', [
                    'conversation_id' => $this->conversation->id,
                    'tool' => $toolCall->name,
                ]);

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
        return in_array($toolName, self::BUILTIN_TOOLS, true);
    }

    protected function isSystemTool(string $toolName): bool
    {
        return in_array($toolName, self::SYSTEM_TOOLS, true);
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
     * Get all tool schemas (builtin + system + user).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAllToolSchemas(): array
    {
        $tools = [];

        // Builtin tool schemas
        $tools = array_merge($tools, $this->getBuiltinToolSchemas());

        // System tool schemas
        $tools = array_merge($tools, $this->getSystemToolSchemas());

        // User tool schemas
        foreach ($this->conversation->agent->tools as $tool) {
            $tools[] = [
                'name' => $tool->name,
                'description' => $tool->config['description'] ?? '',
                'parameters' => $tool->config['parameters'] ?? [],
            ];
        }

        return $tools;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getBuiltinToolSchemas(): array
    {
        return [
            [
                'name' => 'bash',
                'description' => 'Execute a bash command on the client system',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => 'The bash command to execute',
                        ],
                    ],
                    'required' => ['command'],
                ],
            ],
            [
                'name' => 'read',
                'description' => 'Read the contents of a file',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Path to the file to read',
                        ],
                    ],
                    'required' => ['file_path'],
                ],
            ],
            [
                'name' => 'write',
                'description' => 'Write content to a file',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Path to the file to write',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Content to write to the file',
                        ],
                    ],
                    'required' => ['file_path', 'content'],
                ],
            ],
            [
                'name' => 'edit',
                'description' => 'Edit a file by replacing old text with new text',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Path to the file to edit',
                        ],
                        'old_string' => [
                            'type' => 'string',
                            'description' => 'The text to find and replace',
                        ],
                        'new_string' => [
                            'type' => 'string',
                            'description' => 'The text to replace with',
                        ],
                    ],
                    'required' => ['file_path', 'old_string', 'new_string'],
                ],
            ],
            [
                'name' => 'glob',
                'description' => 'Find files matching a pattern',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => [
                            'type' => 'string',
                            'description' => 'Glob pattern to match files',
                        ],
                    ],
                    'required' => ['pattern'],
                ],
            ],
            [
                'name' => 'grep',
                'description' => 'Search for a pattern in files',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => [
                            'type' => 'string',
                            'description' => 'Pattern to search for',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'Path to search in',
                        ],
                    ],
                    'required' => ['pattern'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getSystemToolSchemas(): array
    {
        return [
            [
                'name' => 'todo_add',
                'description' => 'Add a new todo item for this agent',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'item' => [
                            'type' => 'string',
                            'description' => 'The todo item description',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'description' => 'Priority level: low, medium, high',
                            'enum' => ['low', 'medium', 'high'],
                        ],
                    ],
                    'required' => ['item'],
                ],
            ],
            [
                'name' => 'todo_list',
                'description' => 'List all todo items for this agent',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
            [
                'name' => 'todo_complete',
                'description' => 'Mark a todo item as complete',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'The todo item ID',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'todo_update',
                'description' => 'Update a todo item',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'The todo item ID',
                        ],
                        'item' => [
                            'type' => 'string',
                            'description' => 'Updated todo description',
                        ],
                    ],
                    'required' => ['id', 'item'],
                ],
            ],
            [
                'name' => 'todo_delete',
                'description' => 'Delete a todo item',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'The todo item ID to delete',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'todo_clear',
                'description' => 'Clear all todo items for this agent',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
        ];
    }

    // Todo system tool implementations

    protected function todoAdd(array $args): ToolResult
    {
        $agent = $this->conversation->agent;
        $metadata = $agent->metadata ?? [];
        $todos = $metadata['todos'] ?? [];

        $todo = [
            'id' => uniqid('todo_'),
            'item' => $args['item'],
            'priority' => $args['priority'] ?? 'medium',
            'completed' => false,
            'created_at' => now()->toISOString(),
        ];

        $todos[] = $todo;
        $metadata['todos'] = $todos;

        $agent->update(['metadata' => $metadata]);

        return new ToolResult(
            success: true,
            output: "Added todo: {$args['item']} (priority: {$todo['priority']})",
            error: null
        );
    }

    protected function todoList(): ToolResult
    {
        $agent = $this->conversation->agent;
        $metadata = $agent->metadata ?? [];
        $todos = $metadata['todos'] ?? [];

        if (empty($todos)) {
            return new ToolResult(
                success: true,
                output: 'No todos found.',
                error: null
            );
        }

        $output = "Todos:\n";
        foreach ($todos as $todo) {
            $status = $todo['completed'] ? '[✓]' : '[ ]';
            $output .= "{$status} {$todo['id']}: {$todo['item']} ({$todo['priority']})\n";
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    protected function todoComplete(array $args): ToolResult
    {
        $agent = $this->conversation->agent;
        $metadata = $agent->metadata ?? [];
        $todos = $metadata['todos'] ?? [];

        $found = false;
        foreach ($todos as &$todo) {
            if ($todo['id'] === $args['id']) {
                $todo['completed'] = true;
                $todo['completed_at'] = now()->toISOString();
                $found = true;
                break;
            }
        }

        if (! $found) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Todo not found: {$args['id']}"
            );
        }

        $metadata['todos'] = $todos;
        $agent->update(['metadata' => $metadata]);

        return new ToolResult(
            success: true,
            output: "Marked todo {$args['id']} as complete",
            error: null
        );
    }

    protected function todoUpdate(array $args): ToolResult
    {
        $agent = $this->conversation->agent;
        $metadata = $agent->metadata ?? [];
        $todos = $metadata['todos'] ?? [];

        $found = false;
        foreach ($todos as &$todo) {
            if ($todo['id'] === $args['id']) {
                $todo['item'] = $args['item'];
                $todo['updated_at'] = now()->toISOString();
                $found = true;
                break;
            }
        }

        if (! $found) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Todo not found: {$args['id']}"
            );
        }

        $metadata['todos'] = $todos;
        $agent->update(['metadata' => $metadata]);

        return new ToolResult(
            success: true,
            output: "Updated todo {$args['id']}",
            error: null
        );
    }

    protected function todoDelete(array $args): ToolResult
    {
        $agent = $this->conversation->agent;
        $metadata = $agent->metadata ?? [];
        $todos = $metadata['todos'] ?? [];

        $filtered = array_filter($todos, fn ($todo) => $todo['id'] !== $args['id']);

        if (count($filtered) === count($todos)) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Todo not found: {$args['id']}"
            );
        }

        $metadata['todos'] = array_values($filtered);
        $agent->update(['metadata' => $metadata]);

        return new ToolResult(
            success: true,
            output: "Deleted todo {$args['id']}",
            error: null
        );
    }

    protected function todoClear(): ToolResult
    {
        $agent = $this->conversation->agent;
        $metadata = $agent->metadata ?? [];

        $count = count($metadata['todos'] ?? []);
        $metadata['todos'] = [];

        $agent->update(['metadata' => $metadata]);

        return new ToolResult(
            success: true,
            output: "Cleared {$count} todos",
            error: null
        );
    }
}
