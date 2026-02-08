<?php

namespace App\Services\Tools;

use App\DTOs\ToolResult;
use App\Models\Conversation;
use App\Models\Todo;
use Illuminate\Support\Facades\DB;

class TodoToolHandler
{
    public function __construct(
        protected Conversation $conversation
    ) {}

    /**
     * Execute a todo tool by name.
     *
     * @param  array<string, mixed>  $args
     */
    public function execute(string $toolName, array $args): ToolResult
    {
        return match ($toolName) {
            'todo_add' => $this->add($args),
            'todo_list' => $this->list(),
            'todo_complete' => $this->complete($args),
            'todo_update' => $this->update($args),
            'todo_delete' => $this->delete($args),
            'todo_clear' => $this->clear(),
            default => new ToolResult(
                success: false,
                output: '',
                error: "Unknown todo tool: {$toolName}"
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function add(array $args): ToolResult
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

    protected function list(): ToolResult
    {
        $todos = $this->baseQuery()
            ->orderBy(DB::raw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END"))
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

    /**
     * @param  array<string, mixed>  $args
     */
    protected function complete(array $args): ToolResult
    {
        $todo = $this->findTodo($args['id']);

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

    /**
     * @param  array<string, mixed>  $args
     */
    protected function update(array $args): ToolResult
    {
        $todo = $this->findTodo($args['id']);

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

    /**
     * @param  array<string, mixed>  $args
     */
    protected function delete(array $args): ToolResult
    {
        $todo = $this->findTodo($args['id']);

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

    protected function clear(): ToolResult
    {
        $count = $this->baseQuery()->count();
        $this->baseQuery()->delete();

        return new ToolResult(
            success: true,
            output: "Cleared {$count} todos",
            error: null
        );
    }

    /**
     * Get the base query scoped to this conversation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Todo>
     */
    protected function baseQuery(): mixed
    {
        return Todo::where('agent_id', $this->conversation->agent_id)
            ->where('conversation_id', $this->conversation->id);
    }

    /**
     * Find a todo by ID within this conversation's scope.
     */
    protected function findTodo(mixed $id): ?Todo
    {
        $id = is_numeric($id) ? (int) $id : $id;

        return $this->baseQuery()->where('id', $id)->first();
    }
}
